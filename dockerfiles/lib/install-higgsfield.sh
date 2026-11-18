#!/bin/sh
set -eu

# Official CLI is required to use subscription credits and vendor session refresh.
case "$(uname -m)" in
    x86_64) arch=amd64; checksum=316c0b5e64da575af478ebf518598eefc900d34dac5ce568c24de6fce04406d9 ;;
    aarch64|arm64) arch=arm64; checksum=e48e7aafa946acbbc33e0449f95527e35e11672a4c0e70e75f956bdfb5608a08 ;;
    *) echo 'Unsupported Higgsfield CLI architecture' >&2; exit 1 ;;
esac
download_dir=$(mktemp -d)
trap 'rm -rf "$download_dir"' EXIT
curl --fail --silent --show-error --location \
    "https://github.com/higgsfield-ai/cli/releases/download/v1.1.20/hf_1.1.20_linux_${arch}.tar.gz" \
    --output "$download_dir/hf.tar.gz"
printf '%s  %s\n' "$checksum" "$download_dir/hf.tar.gz" | sha256sum --check --status
tar -xzf "$download_dir/hf.tar.gz" -C "$download_dir"
install -m 0755 "$download_dir/hf" /usr/local/bin/higgsfield
/usr/local/bin/higgsfield version
