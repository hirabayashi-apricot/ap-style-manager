#!/bin/zsh
# GitHubのリリースに添付するzipを作る（フォルダ名 ap-style-manager・vendor入り・gitの管理外のファイルは入れない）
set -e
cd "$(dirname "$0")"
VER=$(grep -m1 '^Version:' ap-style-manager.php | sed 's/Version: *//')
OUT="$PWD/dist/ap-style-manager-$VER.zip"
TMP=$(mktemp -d)
mkdir -p dist "$TMP/ap-style-manager"
git archive HEAD | tar -x -C "$TMP/ap-style-manager"
rm -f "$TMP/ap-style-manager/build.sh" "$TMP/ap-style-manager/.gitignore" "$TMP/ap-style-manager/README.md"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" ap-style-manager)
rm -rf "$TMP"
echo "built: $OUT"
