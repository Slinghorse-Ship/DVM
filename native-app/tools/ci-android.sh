#!/usr/bin/env bash
set -euo pipefail

[[ "${GITHUB_ACTIONS:-}" == true && "$(uname -s)" == Linux ]] || { echo 'Use the isolated Linux GitHub runner for this acceptance.' >&2; exit 2; }
[[ -r /dev/kvm && -w /dev/kvm ]] || { echo 'Working hardware acceleration is required; no software-emulator success substitute.' >&2; exit 2; }
: "${DVM_MOBILE_TOOLS:?Choose a fresh private runner tool directory}"
: "${DVM_APP_ARTIFACT_DIR:?Choose an artifact directory outside the checkout}"
task_app_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$task_app_root"
python3 - "$DVM_MOBILE_TOOLS" "$DVM_APP_ARTIFACT_DIR" "$task_app_root" <<'PY'
from pathlib import Path
import sys
for value in sys.argv[1:3]:
    path=Path(value).resolve()
    if path.is_relative_to(Path(sys.argv[3]).resolve()):raise SystemExit('Build tools and artifacts must stay outside the source checkout')
if (Path(sys.argv[2])/'result.json').exists():raise SystemExit('Preserve previous evidence; use a fresh artifact directory')
PY
mkdir -p "$DVM_APP_ARTIFACT_DIR"
task_emulator_pid=''
task_logcat_pid=''
task_android_ready=0
task_android_passed=0

task_android_finish() {
  task_android_exit=$?
  trap - EXIT
  if [[ "$task_android_exit" == 0 && "$task_android_passed" != 1 ]]; then task_android_exit=1; fi
  if [[ "$task_android_ready" == 1 ]]; then
    timeout 15s "$DVM_MOBILE_TOOLS/android-sdk/platform-tools/adb" -s emulator-5554 exec-out screencap -p > "$DVM_APP_ARTIFACT_DIR/android-diagnostic.png" 2>/dev/null || true
    timeout 20s "$DVM_MOBILE_TOOLS/android-sdk/platform-tools/adb" -s emulator-5554 shell uiautomator dump /sdcard/dvm-ci-final.xml >/dev/null 2>&1 || true
    timeout 5s "$DVM_MOBILE_TOOLS/android-sdk/platform-tools/adb" -s emulator-5554 shell cat /sdcard/dvm-ci-final.xml > "$DVM_APP_ARTIFACT_DIR/android-ui.xml" 2>/dev/null || true
  fi
  if [[ -n "$task_logcat_pid" && "$(ps -o ppid= -p "$task_logcat_pid" | tr -d '[:space:]')" == "$$" ]]; then kill "$task_logcat_pid" 2>/dev/null || true; fi
  if [[ -n "$task_emulator_pid" && "$(ps -o ppid= -p "$task_emulator_pid" | tr -d '[:space:]')" == "$$" ]]; then kill "$task_emulator_pid" 2>/dev/null || true; fi
  python3 - "$DVM_APP_ARTIFACT_DIR" "$task_android_exit" "$task_android_passed" <<'PY'
from pathlib import Path
import json,sys,os
path=Path(sys.argv[1]);result={'suite':'native-android-app','status':'passed'if sys.argv[2]=='0'and sys.argv[3]=='1'else'failed','exit_code':int(sys.argv[2]),'physical_device':False,'hardware_acceleration_required':True,'production_signing':False,'live_payments':False,'source_commit':os.environ.get('GITHUB_SHA')}
(path/'result.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
PY
  exit "$task_android_exit"
}
trap task_android_finish EXIT

python3 native-app/tools/android.py setup --cache "$DVM_MOBILE_TOOLS" --accept-android-licenses --emulator | tee "$DVM_APP_ARTIFACT_DIR/setup.log"
python3 native-app/tools/android.py policy-test --cache "$DVM_MOBILE_TOOLS" | tee "$DVM_APP_ARTIFACT_DIR/origin-policy.log"
python3 native-app/tools/android.py build --cache "$DVM_MOBILE_TOOLS" | tee "$DVM_APP_ARTIFACT_DIR/build-lint.log"
python3 native-app/tools/android.py release-check --cache "$DVM_MOBILE_TOOLS" | tee "$DVM_APP_ARTIFACT_DIR/unsigned-release.log"
export JAVA_HOME="$DVM_MOBILE_TOOLS/jdk"
export ANDROID_HOME="$DVM_MOBILE_TOOLS/android-sdk"
export ANDROID_SDK_ROOT="$ANDROID_HOME"
export ANDROID_USER_HOME="$DVM_MOBILE_TOOLS/android-user"
export ANDROID_AVD_HOME="$DVM_MOBILE_TOOLS/avd-registry"
export PATH="$JAVA_HOME/bin:$ANDROID_HOME/platform-tools:$ANDROID_HOME/emulator:$ANDROID_HOME/cmdline-tools/latest/bin:$PATH"
unset ANDROID_SDK_HOME
for task_apk in native-app/android/app/build/outputs/apk/{standard,terminal}/debug/*.apk; do
  "$ANDROID_HOME/build-tools/36.0.0/apksigner" verify --verbose "$task_apk"
done
python3 - "$DVM_APP_ARTIFACT_DIR" <<'PYAPK'
from pathlib import Path
import hashlib,json,sys
files=[]
for flavor in ['standard','terminal']:
    found=list(Path('native-app/android/app/build/outputs/apk',flavor,'debug').glob('*.apk'))
    if len(found)!=1:raise SystemExit('Exactly one built APK per flavor is required')
    path=found[0];files.append({'flavor':flavor,'build':'debug','signed':True,'bytes':path.stat().st_size,'sha256':hashlib.file_digest(path.open('rb'),'sha256').hexdigest()})
    release=list(Path('native-app/android/app/build/outputs/apk',flavor,'release').glob('*-unsigned.apk'))
    if len(release)!=1:raise SystemExit('Exactly one unsigned release APK per flavor is required')
    path=release[0];files.append({'flavor':flavor,'build':'release','signed':False,'bytes':path.stat().st_size,'sha256':hashlib.file_digest(path.open('rb'),'sha256').hexdigest()})
Path(sys.argv[1],'apk-inventory.json').write_text(json.dumps(files,indent=2)+'\n')
PYAPK
mkdir -p "$ANDROID_AVD_HOME"
emulator -accel-check | tee "$DVM_APP_ARTIFACT_DIR/acceleration.log"
adb start-server
if adb devices | awk '$1=="emulator-5554" {found=1} END {exit !found}'; then echo 'Refusing to reuse another emulator.' >&2; exit 2; fi
printf 'no\n' | avdmanager create avd --name dvm-api36 --package 'system-images;android-36;default;x86_64' --device pixel_7 --path "$DVM_MOBILE_TOOLS/avd-data"
# The Pixel profile defaults to 12 GB of userdata. Only this newly owned CI AVD
# needs 2 GB; preserve all installed tools and never reclaim another device's data.
python3 - "$DVM_MOBILE_TOOLS/avd-data/config.ini" "$DVM_APP_ARTIFACT_DIR" <<'PYAVD'
from pathlib import Path
import json, shutil, sys
config=Path(sys.argv[1]);values=dict(line.split('=',1)for line in config.read_text().splitlines()if '='in line)
if values.get('AvdId')!='dvm-api36':raise SystemExit('Refusing to alter a different AVD')
values.update({'disk.dataPartition.size':'2048M','hw.sdCard':'no'})
config.write_text(''.join(key+'='+value+'\n'for key,value in values.items()))
free=shutil.disk_usage(config.parent).free
result={'suite':'owned-android-storage','avd':'dvm-api36','userdata_mib':2048,'sd_card':False,'free_bytes':free,'existing_data_deleted':False}
Path(sys.argv[2],'storage.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
if free<2560*1024*1024:raise SystemExit('Fresh CI AVD needs at least 2.5 GiB free; no existing data will be deleted')
PYAVD
emulator -avd dvm-api36 -port 5554 -accel on -partition-size 2048 -memory 3072 -cores 2 -no-window -no-audio -no-boot-anim -no-snapshot -gpu swiftshader_indirect -feature -Vulkan -camera-back none -camera-front none > "$DVM_APP_ARTIFACT_DIR/emulator.log" 2>&1 &
task_emulator_pid=$!
for task_boot_attempt in $(seq 1 90); do
  kill -0 "$task_emulator_pid" || { echo 'The owned emulator exited before boot.' >&2; exit 1; }
  if [[ "$(timeout 5s adb -s emulator-5554 shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')" == 1 ]]; then task_android_ready=1; break; fi
  sleep 2
done
[[ "$task_android_ready" == 1 ]] || { echo 'The owned accelerated Android device did not boot.' >&2; exit 1; }
[[ "$(adb -s emulator-5554 shell getprop ro.boot.qemu.avd_name | tr -d '\r')" == dvm-api36 ]] || { echo 'Wrong device identity.' >&2; exit 1; }
adb -s emulator-5554 logcat -v threadtime > "$DVM_APP_ARTIFACT_DIR/logcat.log" 2>&1 &
task_logcat_pid=$!
for task_animation in window_animation_scale transition_animation_scale animator_duration_scale; do adb -s emulator-5554 shell settings put global "$task_animation" 0; done
adb -s emulator-5554 shell input keyevent KEYCODE_WAKEUP
adb -s emulator-5554 shell wm dismiss-keyguard
adb -s emulator-5554 install native-app/android/app/build/outputs/apk/standard/debug/app-standard-debug.apk
export DVM_ANDROID_APP_BROWSER=1 DVM_ANDROID_OWNED_EMULATOR=synthetic-only
export DVM_ANDROID_ADB="$ANDROID_HOME/platform-tools/adb" DVM_ANDROID_SERIAL=emulator-5554
task_android_artifacts=$DVM_APP_ARTIFACT_DIR
for task_app_locale in de en; do
  DVM_APP_ARTIFACT_DIR="$task_android_artifacts/$task_app_locale" DVM_ANDROID_UI_LOCALE="$task_app_locale" php vereinsverwaltung/tests/ci-native.php native-manager-controls | tee "$task_android_artifacts/actual-manager-$task_app_locale.log"
  python3 - "$task_android_artifacts/$task_app_locale/android-app-result.json" "$task_app_locale" <<'PYRESULT'
from pathlib import Path
import json,sys
result=json.loads(Path(sys.argv[1]).read_text())
if result.get('suite')!='android-app-emulator'or result.get('checks',0)<13 or result.get('physical_device')is not False or result.get('ui_locale')!=sys.argv[2]:raise SystemExit('Actual installed app and selected-language proof is incomplete')
PYRESULT
done

task_android_passed=1
