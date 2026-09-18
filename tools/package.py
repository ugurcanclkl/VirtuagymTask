"""Create a source-only submission ZIP; never walk vendor, storage or local secrets."""

from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parents[1]
files = [root / name for name in [
    'README.md', 'VERIFICATION.md', 'composer.json', 'composer.lock',
    'artisan', 'phpunit.xml', '.env.example', '.gitignore',
    'bootstrap/cache/.gitkeep', 'storage/.gitkeep',
]]
for folder, suffix in [
    ('app', '.php'), ('config', '.php'), ('routes', '.php'),
    ('public', '.php'), ('database/migrations', '.php'), ('tests', '.php'),
    ('docs', '.md'), ('examples', '.http'), ('examples', '.json'), ('tools', '.php'), ('tools', '.py'),
]:
    files.extend((root / folder).rglob('*' + suffix))
files.append(root / 'bootstrap/app.php')
output = root / 'dist/virtuagym-task.zip'
output.parent.mkdir(exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for file in sorted(set(files)):
        archive.write(file, 'virtuagym-task/' + file.relative_to(root).as_posix())
print(f'Packaged {len(set(files))} source/documentation files: {output.relative_to(root)}')
