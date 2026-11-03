# Contributing

Thanks for taking the time. This is a Mautic plugin, which makes the development setup a
little unusual: most of the code only compiles with Mautic around it.

## Getting a working checkout

Clone into the `plugins` directory of a Mautic 7 installation:

```bash
cd /path/to/mautic/plugins
git clone https://github.com/woutersf/MauticMcpBundle.git MauticMcpBundle
cd ../
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

The directory name matters. Mautic derives the bundle name from it, so it has to be
`MauticMcpBundle`.

## Running the checks

Some checks need nothing but PHP, some need the surrounding Mautic installation.

| Command | Needs Mautic? | What it covers |
| --- | --- | --- |
| `composer lint` | no | Syntax of every PHP file. |
| `composer unit` | no | Unit tests that touch no Mautic class. |
| `composer phpstan` | no | Static analysis of the standalone code. |
| `composer test` | no | The three above. |
| `composer phpunit` | yes | The full suite, through Mautic's own PHPUnit. |

`Tests/bootstrap.php` autoloads the plugin itself and then picks up the Mautic autoloader
if it finds one, which is why the same `phpunit.xml` serves both cases.

## Writing tests

Put a test that needs no Mautic class in `Tests/Unit`. It then runs in CI, in a bare
checkout, on every supported PHP version. Put anything that boots Mautic in
`Tests/Functional`, which runs only inside an installation.

Follow the conventions in Mautic core: PHPUnit attributes rather than annotations
(`#[DataProvider]`, not `@dataProvider`), `declare(strict_types=1)` at the top of every
file, and `final` on classes that are not designed to be extended.

## Branches

`main` is the development branch. `7.x` is the support branch for Mautic 7. Fixes land on
`main` and are merged into the support branch when they apply there. Target your pull
request at `main` unless the change is specific to one Mautic generation.

## Pull requests

- One logical change per pull request.
- Explain the behaviour that was wrong, not only the code that changed. A commit message
  that quotes the failing output or the offending line ages far better than one that says
  "fix bug".
- Add a `CHANGELOG.md` entry under `Unreleased`.
