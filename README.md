# LocalCI (GP variant)

gp-localci is a Github-oriented localization continuous integration add-on to GlotPress. LocalCI provides string coverage management and associated messaging coordination between Github and an external CI build system (eg, CircleCI, TravisCI).

## Requirements
- WordPress instance
- GlotPress plugin (https://wordpress.org/plugins/glotpress/) installed
- PHP 7.0.0 or greater
- CI account (CircleCI, unless you want to write a new adapter. PRs welcome!)

## Installation
1. Put this plugin in the folder: `/glotpress/plugins/`
2. Ensure `/gp-localci/config.php` is locked down as possible (file permissions, move it out of webroot, etc).
3. Set the `LOCALCI_DESIRED_LOCALES` and `LOCALCI_GITHUB_API_MANAGEMENT_TOKEN` defines.
4. Set up entries in `config.php`'s `$repo_metadata`. All lowercase keys. An example entry:

```
'automattic/wp-calypso' => array(
	'build_ci_api_token'  => '_circleci_artifact_only_token_here_',
	'gp_project_id'       => 1,
)
```

## .pot Generation
If using the CircleCI adapter (presumably), add logic to your Circle YAML config to generate a pot of new strings at `$CIRCLE_ARTIFACTS/translate/localci-new-strings.pot`. We recommend `msgcat -u` to do the heavy lifting. For example:

```
? |
  I18N_DIR=$CIRCLE_ARTIFACTS/translate;
  mkdir -p $I18N_DIR;
  make translate;
  mv calypso-strings.pot $I18N_DIR/calypso-strings.pot;
  if [[ $CIRCLE_BRANCH != "master" ]]; then
    git merge-base --fork-point master | xargs git checkout;
    make translate;
    mv calypso-strings.pot $I18N_DIR/calypso-strings-master.pot;
    cp $I18N_DIR/calypso-strings-master.pot $I18N_DIR/calypso-strings-master-copy.pot;
    msgcat -u $I18N_DIR/calypso-strings*.pot > $I18N_DIR/localci-new-strings.pot;
    rm $I18N_DIR/calypso-strings-master-copy.pot;
  fi;
 ```
