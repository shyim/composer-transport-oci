import base64, sys, uuid, io, json, hashlib, urllib.request, subprocess, tempfile, pathlib, os, zipfile, tarfile, shutil
port=subprocess.check_output(['docker','port',os.environ.get('OCI_TEST_CONTAINER', 'composer-oci-plugin-validation'),'5000/tcp'],text=True).strip().split(':')[-1]
base='http://127.0.0.1:'+port
run_id=uuid.uuid4().hex[:12]
root=pathlib.Path(tempfile.mkdtemp(prefix='composer-oci-integration-'))
def req(url,data=None,method='GET',headers=None):
    headers=dict(headers or {})
    if os.environ.get('OCI_TEST_PASSWORD'):
        headers['Authorization']='Basic '+base64.b64encode(('test:'+os.environ['OCI_TEST_PASSWORD']).encode()).decode()
    return urllib.request.urlopen(urllib.request.Request(url,data=data,method=method,headers=headers))
def blob(repo,data,media):
    digest='sha256:'+hashlib.sha256(data).hexdigest()
    location=req(base+'/v2/'+repo+'/blobs/uploads/',b'', 'POST').headers['Location']
    req(location+'&digest='+digest,data,'PUT',{'Content-Type':'application/octet-stream'}).read()
    return dict(mediaType=media,digest=digest,size=len(data))
try:
    for ext,media in [('zip','application/zip'),('tar','application/x-tar'),('tar.gz','application/gzip')]:
        name=ext.replace('.','-'); repo='acme/'+run_id+'-'+name
        metadata=json.dumps({'name':repo,'version':'1.2.3','autoload':{'files':['hello.php']}}).encode()
        files={'composer.json':metadata,'hello.php':b'<?php function oci_test_value() { return "OCI works"; }'}
        buf=io.BytesIO()
        if ext=='zip':
            with zipfile.ZipFile(buf,'w') as archive:
                for path,data in files.items(): archive.writestr(path,data)
        else:
            with tarfile.open(fileobj=buf,mode='w:gz' if ext=='tar.gz' else 'w') as archive:
                for path,data in files.items():
                    info=tarfile.TarInfo(path);info.size=len(data);archive.addfile(info,io.BytesIO(data))
        manifest={'schemaVersion':2,'mediaType':'application/vnd.oci.image.manifest.v1+json','config':blob(repo,metadata,'application/vnd.composer.package.v1+json'),'layers':[blob(repo,buf.getvalue(),media)]}
        req(base+'/v2/'+repo+'/manifests/1.2.3',json.dumps(manifest).encode(),'PUT',{'Content-Type':manifest['mediaType']}).read()
        project=root/name;project.mkdir()
        config={'name':'test/project','repositories':[{'type':'path','url':str(pathlib.Path(__file__).resolve().parent.parent),'options':{'versions':{'shyim/composer-transport-oci':'dev-main'}}},{'packagist.org':False}],'require':{'shyim/composer-transport-oci':'@dev'},'config':{'allow-plugins':{'shyim/composer-transport-oci':True},'secure-http':False}}
        (project/'composer.json').write_text(json.dumps(config))
        env=dict(os.environ,COMPOSER_HOME=str(root/'home'),COMPOSER_CACHE_DIR=str(root/'cache'),DOCKER_CONFIG=str(root/'docker'))
        if os.environ.get('OCI_TEST_PASSWORD'):
            helpers=root/'helpers'; helpers.mkdir(exist_ok=True)
            docker=root/'docker'; docker.mkdir(exist_ok=True)
            host='127.0.0.1:'+port
            helper=helpers/'docker-credential-integration'
            helper.write_text('#!'+sys.executable+'\nimport sys,json\nassert sys.argv[1] == "get"\nassert sys.stdin.read().strip() == '+repr(host)+'\nprint(json.dumps('+repr({'Username':'test','Secret':os.environ['OCI_TEST_PASSWORD']})+'))\n')
            helper.chmod(0o700)
            (docker/'config.json').write_text(json.dumps({'credHelpers':{host:'integration'}}))
            env['PATH']=str(helpers)+os.pathsep+env['PATH']
        def composer(cmd):
            result=subprocess.run([os.environ.get('COMPOSER_BINARY', '/usr/bin/composer'),cmd,'--no-interaction','--no-progress','--no-scripts'],cwd=project,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
            if result.returncode: raise RuntimeError(result.stdout)
        composer('update')  # Bootstrap the plugin before resolving OCI dependencies.
        config['extra']={'oci-repositories':[base+'/'+repo]}
        config['require'][repo]='^1.2'
        (project/'composer.json').write_text(json.dumps(config))
        composer('update')
        lock=json.loads((project/'composer.lock').read_text());assert next(p for p in lock['packages'] if p['name']==repo)['dist']['reference']==manifest['layers'][0]['digest']
        assert next(p for p in lock['packages'] if p['name']==repo)['version']=='1.2.3'
        assert 'Authorization' not in (project/'composer.lock').read_text()
        assert 'file://' not in (project/'composer.lock').read_text()
        assert not os.environ.get('OCI_TEST_PASSWORD') or os.environ['OCI_TEST_PASSWORD'] not in (project/'composer.lock').read_text()
        shutil.rmtree(project/'vendor');shutil.rmtree(root/'cache')
        composer('install')
        result=subprocess.check_output(['php','-r','require "vendor/autoload.php"; echo oci_test_value();'],cwd=project,text=True)
        assert result=='OCI works',result
        upgraded=json.loads(metadata); upgraded['version']='1.3.0'
        manifest['config']=blob(repo,json.dumps(upgraded).encode(),'application/vnd.composer.package.v1+json')
        req(base+'/v2/'+repo+'/manifests/1.3.0',json.dumps(manifest).encode(),'PUT',{'Content-Type':manifest['mediaType']}).read()
        composer('update')
        assert next(p for p in json.loads((project/'composer.lock').read_text())['packages'] if p['name']==repo)['version']=='1.3.0'
        del config['require'][repo]
        (project/'composer.json').write_text(json.dumps(config))
        composer('update')
        assert not (project/'vendor'/repo).exists()
        print(ext+': update, digest lock, uncached locked install, autoload, upgrade and removal PASS')
finally:
    shutil.rmtree(root)
