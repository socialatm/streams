#!/usr/bin/env bash

#FULLPATH=$(dirname $(readlink -f "$0"))

VINFO=`echo "<?php include 'boot.php'; echo REPOSITORY_ID . \" \" . STD_VERSION . \"\\n\";" | php`

PROJECTNAME=streams
F9KVERSION=`echo $VINFO | awk '{print $2;}'`


ADDONMODE=
ADDONNAME=
if [ "$1" == "--addon" -o "$1" == "-a" ]
then
    ADDONMODE=1
    if [ -z $2 ]; then echo -e "ERROR: missing addon name\n\nrun_xgettext.sh -a <addonname>"; exit 1; fi
    ADDONNAME=$2
    if [ ! -d "$FULLPATH/../addon/$ADDONNAME" ]; then echo "ERROR: addon '$ADDONNAME' not found"; exit 2; fi
fi

if [ $ADDONMODE ]
then
    cd "$FULLPATH/../addon/$ADDONNAME"
    mkdir -p "$FULLPATH/../addon/$ADDONNAME/lang/C"
    OUTFILE="$FULLPATH/../addon/$ADDONNAME/lang/C/messages.po"
    FINDSTARTDIR="."
    FINDOPTS=
else
    OUTFILE="util/messages.po"
    FINDOPTS=
fi



echo "$PROJECTNAME version $F9KVERSION"

OPTS=

#if [ "" != "$1" ]
#then
#	OUTFILE="$(readlink -f ${FULLPATH}/$1)"
#	if [ -e "$OUTFILE" ]
#	then
#		echo "join extracted strings"
#		OPTS="-j"
#	fi
#fi

KEYWORDS="-k -kt:1 -kt:1,2c,2t -ktt:1,2 -ktt:1,2,4c,4t"

echo "extract strings to $OUTFILE.."


rm "$OUTFILE"; touch "$OUTFILE"
for f in $(find `cat util/gtfiles` $FINDOPTS -name "*.php" -type f)
do
    if [ ! -d "$f" ]
    then
        xgettext $KEYWORDS $OPTS -j -o "$OUTFILE" --from-code=UTF-8 "$f" > /dev/null 2>&1
    fi
done

echo "setup base info.."
if [ $ADDONMODE ]
then
    sed -i "s/SOME DESCRIPTIVE TITLE./ADDON $ADDONNAME/g" "$OUTFILE"
    sed -i "s/YEAR THE PACKAGE'S COPYRIGHT HOLDER//g" "$OUTFILE"
    sed -i "s/FIRST AUTHOR <EMAIL@ADDRESS>, YEAR.//g" "$OUTFILE"
    sed -i "s/PACKAGE VERSION//g" "$OUTFILE"
    sed -i "s/PACKAGE/$PROJECTNAME $ADDONNAME addon/g" "$OUTFILE"
    sed -i "s/CHARSET/UTF-8/g" "$OUTFILE"
	sed -i '/^\"Plural-Forms/d' "$OUTFILE"
else
    sed -i "s/SOME DESCRIPTIVE TITLE./$PROJECTNAME/g" "$OUTFILE"
    sed -i "s/YEAR THE PACKAGE'S COPYRIGHT HOLDER/2010-2022 $PROJECTNAME/g" "$OUTFILE"
    sed -i "s/FIRST AUTHOR <EMAIL@ADDRESS>, YEAR./Nobody, 2010/g" "$OUTFILE"
    sed -i "s/PACKAGE VERSION/$F9KVERSION/g" "$OUTFILE"
    sed -i "s/PACKAGE/$PROJECTNAME/g" "$OUTFILE"
    sed -i "s/CHARSET/UTF-8/g" "$OUTFILE"
	sed -i '/^\"Plural-Forms/d' "$OUTFILE"
fi

#grep -v "Plural-Forms:" $OUTFILE > tmpout
#mv tmpout $OUTFILE

echo "done."
