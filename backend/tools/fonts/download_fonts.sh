#!/bin/sh
# Laedt die fuer generate_presets.py benoetigten (freien, OFL-lizenzierten)
# Google-Fonts-Dateien in diesen Ordner. Nicht im Repo (siehe .gitignore) -
# nur zur Build-Zeit der Preset-Rahmen gebraucht, kein Laufzeit-Bestandteil
# von Box oder Backend.
set -e
cd "$(dirname "$0")"
BASE="https://raw.githubusercontent.com/google/fonts/main"
curl -sL -o PlayfairDisplay-Variable.ttf "$BASE/ofl/playfairdisplay/PlayfairDisplay%5Bwght%5D.ttf"
curl -sL -o Poppins-Bold.ttf            "$BASE/ofl/poppins/Poppins-Bold.ttf"
curl -sL -o Poppins-SemiBold.ttf        "$BASE/ofl/poppins/Poppins-SemiBold.ttf"
curl -sL -o Poppins-Regular.ttf         "$BASE/ofl/poppins/Poppins-Regular.ttf"
curl -sL -o Pacifico-Regular.ttf        "$BASE/ofl/pacifico/Pacifico-Regular.ttf"
curl -sL -o Baloo2-Variable.ttf         "$BASE/ofl/baloo2/Baloo2%5Bwght%5D.ttf"
curl -sL -o BebasNeue-Regular.ttf       "$BASE/ofl/bebasneue/BebasNeue-Regular.ttf"
curl -sL -o Caveat-Variable.ttf         "$BASE/ofl/caveat/Caveat%5Bwght%5D.ttf"
echo "Fonts heruntergeladen nach $(pwd)"
