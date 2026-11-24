#!/bin/zsh
# Builds and signs "The Mothership.app".
# Usage: build-app.sh <output-dir> [signing-identity-sha1]
# The signing identity keeps macOS privacy grants across rebuilds. Without one the app is signed ad hoc.
set -euo pipefail

output=${1:?usage: build-app.sh <output-dir> [signing-identity-sha1]}
identity=${2:--}
package=${0:A:h:h}
app="$output/The Mothership.app"

export DEVELOPER_DIR=${DEVELOPER_DIR:-/Applications/Xcode-beta.app/Contents/Developer}
cd "$package"
swift build -c release --product MothershipBar
binary=$(swift build -c release --product MothershipBar --show-bin-path)/MothershipBar

rm -rf "$app"
mkdir -p "$app/Contents/MacOS" "$app/Contents/Resources"
cp "$binary" "$app/Contents/MacOS/MothershipBar"
cp Resources/Info.plist "$app/Contents/Info.plist"
cp Resources/stack-action.sh Resources/linux-host-status.py Resources/lockup-compact-colour-dark.svg Resources/lockup-compact-colour-light.svg "$app/Contents/Resources/"
cp -R Resources/fonts "$app/Contents/Resources/fonts"

iconset=$(mktemp -d "$output/iconset.XXXXXX")/AppIcon.iconset
mkdir -p "$iconset"
for size in 16 32 128 256 512; do
  sips -z $size $size Resources/app-icon.png --out "$iconset/icon_${size}x${size}.png" >/dev/null
  double=$(( size * 2 ))
  sips -z $double $double Resources/app-icon.png --out "$iconset/icon_${size}x${size}@2x.png" >/dev/null
done
iconutil -c icns "$iconset" -o "$app/Contents/Resources/AppIcon.icns"
rm -rf "${iconset:h}"

codesign --force --options runtime --timestamp=none --sign "$identity" "$app"
codesign --verify --strict "$app"
print -r -- "$app"
