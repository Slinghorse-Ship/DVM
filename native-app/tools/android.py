#!/usr/bin/env python3
"""Verified Linux Android setup/build; outputs and device/signing keys stay outside Git."""
import argparse, hashlib, json, os, platform, shutil, subprocess, tarfile, zipfile
from pathlib import Path
from urllib.parse import urlsplit

source=Path(__file__).resolve().parents[1]
lock=json.loads((source/'tools/android-toolchain.json').read_text())
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('action',choices=['setup','build','release-check','policy-test','wrapper'])
parser.add_argument('--cache',type=Path,default=Path(os.environ.get('DVM_MOBILE_TOOLS',str(Path.home()/'.cache/dvm-mobile'))))
parser.add_argument('--accept-android-licenses',action='store_true')
parser.add_argument('--emulator',action='store_true')
args=parser.parse_args();cache=args.cache.resolve();cache.mkdir(parents=True,exist_ok=True)

def run(command,**kwargs):return subprocess.run([str(x)for x in command],check=True,**kwargs)
def download(name,description):
    target=cache/name;algorithm='sha256'if 'sha256'in description else'sha1'
    if target.is_file()and hashlib.file_digest(target.open('rb'),algorithm).hexdigest()==description[algorithm]:return target
    partial=cache/(name+'.partial')
    run(['curl','--fail','--location','--show-error','--silent','--retry','2','--max-time','600',description['url'],'--output',partial])
    with partial.open('rb')as stream:actual=hashlib.file_digest(stream,algorithm).hexdigest()
    if actual!=description[algorithm]:raise SystemExit('Official artifact checksum mismatch: '+name)
    partial.replace(target);return target

def environment():
    env=os.environ.copy();env.update({'JAVA_HOME':str(cache/'jdk'),'ANDROID_HOME':str(cache/'android-sdk'),'ANDROID_SDK_ROOT':str(cache/'android-sdk'),'ANDROID_USER_HOME':str(cache/'android-user'),'GRADLE_USER_HOME':str(cache/'gradle-home'),'GRADLE_OPTS':'-Duser.home='+str(cache/'home')})
    env.pop('ANDROID_SDK_HOME',None)
    return env

def gradle(*tasks):run([cache/'gradle-8.13/bin/gradle','--no-daemon','--max-workers=2','-p',source/'android',*tasks],env=environment())

if args.action=='setup':
    if platform.system()!='Linux'or platform.machine()not in('x86_64','AMD64'):raise SystemExit('Automatic setup requires Linux x64; other hosts use Android Studio/JDK 21 and the checked-in Gradle wrapper.')
    jdk=download('jdk.tar.gz',lock['jdk_linux_x64']);tools=download('android-tools.zip',lock['android_tools']);gradle_zip=download('gradle.zip',lock['gradle'])
    if not(cache/'jdk/bin/javac').is_file():
        (cache/'jdk').mkdir(exist_ok=True)
        run(['tar','-xzf',jdk,'-C',cache/'jdk','--strip-components=1'])
    if not(cache/'android-sdk/cmdline-tools/latest/bin/sdkmanager').is_file():
        stage=cache/'android-tools-stage';stage.mkdir(exist_ok=False)
        with zipfile.ZipFile(tools)as archive:archive.extractall(stage)
        (cache/'android-sdk/cmdline-tools').mkdir(parents=True,exist_ok=True)
        (stage/'cmdline-tools').rename(cache/'android-sdk/cmdline-tools/latest');stage.rmdir()
        for tool in(cache/'android-sdk/cmdline-tools/latest/bin').iterdir():tool.chmod(0o755)
    if not(cache/'gradle-8.13/bin/gradle').is_file():
        with zipfile.ZipFile(gradle_zip)as archive:archive.extractall(cache)
        (cache/'gradle-8.13/bin/gradle').chmod(0o755)
    for folder in['home','prefs','gradle-home','android-user']:(cache/folder).mkdir(exist_ok=True)
    properties=['org.gradle.java.home='+str(cache/'jdk'),'org.gradle.jvmargs=-Xmx1536m -Dfile.encoding=UTF-8 -Duser.home='+str(cache/'home')+' -Djava.util.prefs.userRoot='+str(cache/'prefs')]
    # Use the platform's injected proxy/trust; never disable certificate validation.
    for scheme in['http','https']:
        proxy=urlsplit(os.environ.get(scheme.upper()+'_PROXY',''))
        if proxy.hostname:
            if proxy.username or proxy.password:raise SystemExit('Authenticated proxy needs existing supported Java configuration; credentials are never written here.')
            properties+=['systemProp.'+scheme+'.proxyHost='+proxy.hostname,'systemProp.'+scheme+'.proxyPort='+str(proxy.port or 8080)]
    trust=Path('/etc/ssl/certs/java/cacerts')
    if trust.is_file():properties+=['systemProp.javax.net.ssl.trustStore='+str(trust)]
    properties_file=cache/'gradle-home/gradle.properties';content='\n'.join(properties)+'\n'
    if properties_file.exists():
        existing=properties_file.read_text();values=dict(line.split('=',1)for line in existing.splitlines()if '='in line and not line.lstrip().startswith('#'))
        missing=[]
        for line in properties:
            key,value=line.split('=',1)
            if key in values and values[key]!=value:raise SystemExit('Existing private Gradle setting differs: '+key+'; preserve and inspect.')
            if key not in values:missing.append(line)
        if missing:properties_file.write_text(existing.rstrip()+'\n'+'\n'.join(missing)+'\n')
    else:properties_file.write_text(content)
    sdkmanager=cache/'android-sdk/cmdline-tools/latest/bin/sdkmanager'
    env=environment()
    if args.accept_android_licenses:run([sdkmanager,'--licenses'],input='y\n'*100,text=True,env=env)
    run([sdkmanager,'--install',*lock['sdk_packages'],*(lock['emulator_packages']if args.emulator else[])],env=env)
    print('Pinned Android SDK/JDK/Gradle ready; existing app data and keys retained.')
elif args.action=='build':gradle('assembleStandardDebug','lintStandardDebug','assembleTerminalDebug','lintTerminalDebug')
elif args.action=='release-check':gradle('assembleStandardRelease','lintStandardRelease','assembleTerminalRelease','lintTerminalRelease')
elif args.action=='wrapper':gradle('wrapper','--gradle-version',lock['gradle']['version'],'--distribution-type','bin','--gradle-distribution-sha256-sum',lock['gradle']['sha256'])
elif args.action=='policy-test':
    output=cache/'origin-policy-test';output.mkdir(exist_ok=True)
    run([cache/'jdk/bin/javac','-d',output,source/'android/app/src/main/java/de/deinvereinsmanager/app/OriginPolicy.java',source/'tests/OriginPolicyTest.java'])
    run([cache/'jdk/bin/java','-cp',output,'OriginPolicyTest'])
