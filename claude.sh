#!/bin/bash
DIR="$(cd -- "$(dirname -- "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
NAME=`basename $DIR`
cd "$DIR"
SESSION="claude_$NAME"

if screen -ls | grep -q "[.]${SESSION}[[:space:]]"; then
    screen -dr "$SESSION"
else
    screen -S "$SESSION" claude
fi
