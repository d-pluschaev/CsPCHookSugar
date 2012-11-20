#!/bin/sh

if [ $# -ne 1 ]
then
  echo 'You should specify path to your git repository as argument'
  exit 1;
fi

DIR="$1/.git/hooks"

if [ -d $DIR ]; then
    echo "Installing to $1..."
else
    echo "Error: $DIR is not exists"
    exit 1;
fi

PCH_SOURCE="$PWD/pre-commit/pre-commit"
PCH_TARGET="$DIR/pre-commit"

cp $PCH_SOURCE $PCH_TARGET

if [ $? -eq 0 ]; then
    chmod +x $PCH_TARGET

    PCHDIR_SOURCE="$PWD/pre-commit/cs"
    PCHDIR_TARGET="$DIR/cs"

    cp -r $PCHDIR_SOURCE $PCHDIR_TARGET

    if [ $? -eq 0 ]; then
        echo "Installed successfully. You can check it when committing"
    else
        echo "Unable to copy directory $PCHDIR_SOURCE to $PCHDIR_TARGET"
    fi
else
    echo "Unable to copy $PCH_SOURCE to $PCH_TARGET"
fi

