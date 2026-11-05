#!/usr/bin/env python3
"""Deterministic native iPhone/iPad project; no downloaded project template or signing key."""
from pathlib import Path
import hashlib,json,plistlib
root=Path(__file__).resolve().parents[1]/'ios'
objects={}
def ident(name):return hashlib.sha256(name.encode()).hexdigest()[:24].upper()
def ref(name):return ident(name)
def add(key,isa,**data):objects[ref(key)]={'isa':isa,**data};return ref(key)
def source(name,path,kind):return add(name,'PBXFileReference',lastKnownFileType=kind,path=path,sourceTree='<group>')
app=[];sources=[]
for filename in ['main.m','DVMAppDelegate.h','DVMAppDelegate.m','DVMSceneDelegate.h','DVMSceneDelegate.m','DVMOrigin.h','DVMOrigin.m','DVMNativeBridge.h','DVMNativeBridge.m','DVMViewController.h','DVMViewController.m','Info.plist','Info-Debug.plist']:
 key='app:'+filename;app.append(source(key,filename,'sourcecode.c.objc'if filename.endswith('.m')else'sourcecode.c.h'if filename.endswith('.h')else'text.plist.xml'))
 if filename.endswith('.m'):sources.append(add('build:'+filename,'PBXBuildFile',fileRef=ref(key)))
resources=[]
for filename,kind in [('Assets.xcassets','folder.assetcatalog'),('PrivacyInfo.xcprivacy','text.xml')]:
 key='app:'+filename;app.append(source(key,filename,kind));resources.append(add('build:'+filename,'PBXBuildFile',fileRef=ref(key)))
for filename in ['Localizable.strings','InfoPlist.strings']:
 children=[]
 for locale in ['de','en']:
  key='localized:'+locale+':'+filename
  children.append(add(key,'PBXFileReference',lastKnownFileType='text.plist.strings',name=locale,path=locale+'.lproj/'+filename,sourceTree='<group>'))
 group=add('localized:'+filename,'PBXVariantGroup',children=children,name=filename,sourceTree='<group>');app.append(group);resources.append(add('build:localized:'+filename,'PBXBuildFile',fileRef=group))
for filename in ['KulturhusPaymentBridge.h','KulturhusPaymentBridge.m']:
 key='payment:'+filename;app.append(source(key,'../'+filename,'sourcecode.c.objc'if filename.endswith('.m')else'sourcecode.c.h'))
 if filename.endswith('.m'):sources.append(add('build:'+filename,'PBXBuildFile',fileRef=ref(key)))
shared=source('shared-js','../../shared/native-client.js','sourcecode.javascript');app.append(shared);resources.append(add('build:shared-js','PBXBuildFile',fileRef=shared))
testfile=source('testsource','DVMOriginTests.m','sourcecode.c.objc');testbuild=add('testbuild','PBXBuildFile',fileRef=testfile)
product=add('appproduct','PBXFileReference',explicitFileType='wrapper.application',includeInIndex='0',path='DVM.app',sourceTree='BUILT_PRODUCTS_DIR')
tests=add('testproduct','PBXFileReference',explicitFileType='wrapper.cfbundle',includeInIndex='0',path='DVMTests.xctest',sourceTree='BUILT_PRODUCTS_DIR')
frameworks=[];appframeworks=[];testframeworks=[]
for name in ['UIKit','WebKit','Foundation','UserNotifications','XCTest']:
 key='framework:'+name;frameworks.append(add(key,'PBXFileReference',lastKnownFileType='wrapper.framework',name=name+'.framework',path=('Developer/Library/Frameworks/'if name=='XCTest'else'System/Library/Frameworks/')+name+'.framework',sourceTree='PLATFORM_DIR'if name=='XCTest'else'SDKROOT'))
 if name!='XCTest':appframeworks.append(add('frameworkbuild:'+name,'PBXBuildFile',fileRef=ref(key)))
 else:testframeworks.append(add('frameworkbuild:'+name,'PBXBuildFile',fileRef=ref(key)))
add('appgroup','PBXGroup',children=app,path='DVM',sourceTree='<group>');add('testgroup','PBXGroup',children=[testfile],path='DVMTests',sourceTree='<group>')
add('frameworkgroup','PBXGroup',children=frameworks,name='Frameworks',sourceTree='<group>');add('products','PBXGroup',children=[product,tests],name='Products',sourceTree='<group>')
add('rootgroup','PBXGroup',children=[ref('appgroup'),ref('testgroup'),ref('frameworkgroup'),ref('products')],sourceTree='<group>')
add('appsources','PBXSourcesBuildPhase',buildActionMask='2147483647',files=sources,runOnlyForDeploymentPostprocessing='0')
add('appframeworkphase','PBXFrameworksBuildPhase',buildActionMask='2147483647',files=appframeworks,runOnlyForDeploymentPostprocessing='0')
add('appresources','PBXResourcesBuildPhase',buildActionMask='2147483647',files=resources,runOnlyForDeploymentPostprocessing='0')
add('testsources','PBXSourcesBuildPhase',buildActionMask='2147483647',files=[testbuild],runOnlyForDeploymentPostprocessing='0')
add('testframeworkphase','PBXFrameworksBuildPhase',buildActionMask='2147483647',files=testframeworks,runOnlyForDeploymentPostprocessing='0')
add('proxy','PBXContainerItemProxy',containerPortal=ref('project'),proxyType='1',remoteGlobalIDString=ref('apptarget'),remoteInfo='DVM')
add('dependency','PBXTargetDependency',target=ref('apptarget'),targetProxy=ref('proxy'))
for config in ['Debug','Release']:
 common={'CLANG_ENABLE_MODULES':'YES','CLANG_ENABLE_OBJC_ARC':'YES','SDKROOT':'iphoneos','IPHONEOS_DEPLOYMENT_TARGET':'17.0','TARGETED_DEVICE_FAMILY':'1,2','SUPPORTED_PLATFORMS':'iphoneos iphonesimulator','SUPPORTS_MACCATALYST':'NO','CODE_SIGN_STYLE':'Automatic','GCC_C_LANGUAGE_STANDARD':'gnu17','GCC_WARN_ABOUT_RETURN_TYPE':'YES_ERROR','CLANG_WARN_OBJC_IMPLICIT_RETAIN_SELF':'YES'}
 if config=='Debug':common.update({'GCC_PREPROCESSOR_DEFINITIONS':['DEBUG=1','$(inherited)'],'GCC_OPTIMIZATION_LEVEL':'0','ONLY_ACTIVE_ARCH':'YES','ENABLE_TESTABILITY':'YES','GCC_SYMBOLS_PRIVATE_EXTERN':'NO'})
 add('projectconfig:'+config,'XCBuildConfiguration',name=config,buildSettings=common)
 add('appconfig:'+config,'XCBuildConfiguration',name=config,buildSettings={'PRODUCT_NAME':'DVM','PRODUCT_BUNDLE_IDENTIFIER':'de.deinvereinsmanager.app','INFOPLIST_FILE':'DVM/Info'+('-Debug'if config=='Debug'else'')+'.plist','ASSETCATALOG_COMPILER_APPICON_NAME':'AppIcon','GENERATE_INFOPLIST_FILE':'NO','DVM_SUMUP_AFFILIATE_KEY':'','HEADER_SEARCH_PATHS':['$(SRCROOT)']})
 add('testconfig:'+config,'XCBuildConfiguration',name=config,buildSettings={'PRODUCT_NAME':'DVMTests','PRODUCT_BUNDLE_IDENTIFIER':'de.deinvereinsmanager.app.tests','GENERATE_INFOPLIST_FILE':'YES','HEADER_SEARCH_PATHS':['$(SRCROOT)/DVM'],'TEST_HOST':'$(BUILT_PRODUCTS_DIR)/DVM.app/DVM','BUNDLE_LOADER':'$(TEST_HOST)'})
for key,prefix in [('projectconfigs','projectconfig'),('appconfigs','appconfig'),('testconfigs','testconfig')]:add(key,'XCConfigurationList',buildConfigurations=[ref(prefix+':Debug'),ref(prefix+':Release')],defaultConfigurationIsVisible='0',defaultConfigurationName='Release')
package=add('sumup-package','XCRemoteSwiftPackageReference',repositoryURL='https://github.com/sumup/sumup-ios-sdk.git',requirement={'kind':'exactVersion','version':'7.2.0'})
packageproduct=add('sumup-product','XCSwiftPackageProductDependency',package=package,productName='SumUpSDK')
objects[ref('appframeworkphase')]['files'].append(add('sumup-build','PBXBuildFile',productRef=packageproduct))
add('apptarget','PBXNativeTarget',buildConfigurationList=ref('appconfigs'),buildPhases=[ref('appsources'),ref('appframeworkphase'),ref('appresources')],buildRules=[],dependencies=[],name='DVM',productName='DVM',productReference=product,productType='com.apple.product-type.application',packageProductDependencies=[packageproduct])
add('testtarget','PBXNativeTarget',buildConfigurationList=ref('testconfigs'),buildPhases=[ref('testsources'),ref('testframeworkphase')],buildRules=[],dependencies=[ref('dependency')],name='DVMTests',productName='DVMTests',productReference=tests,productType='com.apple.product-type.bundle.unit-test')
add('project','PBXProject',attributes={'BuildIndependentTargetsInParallel':'YES','LastUpgradeCheck':'1600'},buildConfigurationList=ref('projectconfigs'),compatibilityVersion='Xcode 14.0',developmentRegion='de',hasScannedForEncodings='0',knownRegions=['de','en','Base'],mainGroup=ref('rootgroup'),productRefGroup=ref('products'),projectDirPath='',projectRoot='',targets=[ref('apptarget'),ref('testtarget')],packageReferences=[package])
def encode(value,indent=0):
 if isinstance(value,dict):return '{\n'+''.join('\t'*(indent+1)+json.dumps(k)+' = '+encode(v,indent+1)+';\n'for k,v in value.items())+'\t'*indent+'}'
 if isinstance(value,list):return '('+', '.join(encode(x,indent)for x in value)+')'
 return json.dumps(value,ensure_ascii=False)
project=root/'DVM.xcodeproj';project.mkdir(exist_ok=True)
(project/'project.pbxproj').write_text('// !$*UTF8*$!\n'+encode({'archiveVersion':'1','classes':{},'objectVersion':'56','objects':objects,'rootObject':ref('project')})+'\n')
scheme=project/'xcshareddata/xcschemes';scheme.mkdir(parents=True,exist_ok=True)
def build(target,name):return '<BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="'+ref(target)+'" BuildableName="'+name+'" BlueprintName="'+name.split('.')[0]+'" ReferencedContainer="container:DVM.xcodeproj"/>'
(scheme/'DVM.xcscheme').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<Scheme LastUpgradeVersion="1600" version="1.7"><BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES">'+build('apptarget','DVM.app')+'</BuildActionEntry></BuildActionEntries></BuildAction><TestAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" shouldUseLaunchSchemeArgsEnv="YES"><Testables><TestableReference skipped="NO">'+build('testtarget','DVMTests.xctest')+'</TestableReference></Testables></TestAction><LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugDocumentVersioning="YES" allowLocationSimulation="YES"><BuildableProductRunnable runnableDebuggingMode="0">'+build('apptarget','DVM.app')+'</BuildableProductRunnable></LaunchAction><ProfileAction buildConfiguration="Release" shouldUseLaunchSchemeArgsEnv="YES" useCustomWorkingDirectory="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0">'+build('apptarget','DVM.app')+'</BuildableProductRunnable></ProfileAction><AnalyzeAction buildConfiguration="Debug"/><ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/></Scheme>\n')
info=plistlib.loads((root/'DVM/Info.plist').read_bytes());info['NSAppTransportSecurity']={'NSAllowsArbitraryLoadsInWebContent':True,'NSAllowsLocalNetworking':True};(root/'DVM/Info-Debug.plist').write_bytes(plistlib.dumps(info,sort_keys=False))
print('Deterministic iPhone/iPad Xcode project and origin tests generated.')

resolved=project/'project.xcworkspace/xcshareddata/swiftpm';resolved.mkdir(parents=True,exist_ok=True)
(resolved/'Package.resolved').write_text(json.dumps({'pins':[{'identity':'sumup-ios-sdk','kind':'remoteSourceControl','location':'https://github.com/sumup/sumup-ios-sdk.git','state':{'revision':'8224b6eb435dd8b06910dfb534c412a4700f98e2','version':'7.2.0'}}],'version':2},indent=2)+'\n')
