#!/bin/bash
# Fix Flutter Linux build: "Failed to find any of [ld.lld, ld] in /usr/lib/llvm-18/bin"
# Run once with: sudo bash fix_linux_linker.sh

set -e
LLVM_BIN="/usr/lib/llvm-18/bin"

echo "Installing lld-18 (LLVM linker)..."
apt-get update -qq
apt-get install -y lld-18

# lld-18 typically installs to /usr/bin/ld.lld-18; Flutter looks in $LLVM_BIN
if [ -x "/usr/bin/ld.lld-18" ]; then
  echo "Linking ld.lld-18 into $LLVM_BIN..."
  ln -sf /usr/bin/ld.lld-18 "$LLVM_BIN/ld.lld"
  ln -sf /usr/bin/ld.lld-18 "$LLVM_BIN/ld"
elif [ -x "/usr/bin/ld.lld" ]; then
  echo "Linking ld.lld into $LLVM_BIN..."
  ln -sf /usr/bin/ld.lld "$LLVM_BIN/ld.lld"
  ln -sf /usr/bin/ld.lld "$LLVM_BIN/ld"
else
  echo "Using system GNU ld as fallback..."
  ln -sf /usr/bin/ld "$LLVM_BIN/ld"
fi

echo "Done. Try: flutter run -d linux"
