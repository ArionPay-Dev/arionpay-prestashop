"""Build the PrestaShop module zip: copies the ArionPay PHP SDK into arionpay/lib, then zips the arionpay/ folder.
   python3 build.py  ->  dist/arionpay-prestashop.zip  (Modules > Module Manager > Upload a module)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
MOD = HERE / "arionpay"
lib = MOD / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
guard = (MOD / "index.php").read_text()
for d in (lib, lib / "src"):
    (d / "index.php").write_text(guard)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "arionpay-prestashop.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(MOD):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, "arionpay/" + str(p.relative_to(MOD)))
print(out, os.path.getsize(out), "bytes")
