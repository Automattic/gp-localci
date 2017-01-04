# LocalCI (GP variant)

gp-localci is a Github-oriented localization continuous integration add-on to GlotPress. LocalCI provides string coverage management and associated messaging coordination between Github and an external CI build system (eg, CircleCI, TravisCI).

## Requirements
- WordPress instance
- GlotPress plugin (https://wordpress.org/plugins/glotpress/) installed
- PHP 7.0.0 or greater
- CI account (CircleCI, unless you want to write a new adapter. PRs welcome!)

## Installation
1. Put this plugin in the folder: `/glotpress/plugins/`.
2. Create a `config.php` file based on `config-sample.php`.
3. Ensure `/gp-localci/config.php` is locked down as possible (file permissions, move it out of webroot, etc).
4. Set the `LOCALCI_DESIRED_LOCALES` and `LOCALCI_GITHUB_API_MANAGEMENT_TOKEN` defines.
5. Set up entries in `config.php`'s `$repo_metadata`. All lowercase keys. An example entry:

```
'automattic/wp-calypso' => array(
	'build_ci_api_token'  => '_circleci_artifact_only_token_here_',
	'gp_project_id'       => 1,
)
```

## .pot Generation
If using the CircleCI adapter (presumably), add logic to your Circle YAML config to generate a pot of new strings at `$CIRCLE_ARTIFACTS/translate/localci-new-strings.pot.

```
post:
  - |
     if [[ "$CIRCLE_BRANCH" != "master" ]]; then
       git clone https://github.com/Automattic/gp-localci-client.git
       bash gp-localci-client/generate-new-strings-pot.sh $CIRCLE_BRANCH $CIRCLE_SHA1 $CIRCLE_ARTIFACTS/translate
       rm -rf gp-localci-client
     fi
```

## Integration
Add a CircleCI webhook (in the YAML config) pointing to your GlotPress instance like so:

```
notify:
  webhooks:
    - url: https://glotpressinstance.example.com/api/localci/-relay-new-strings-to-gh
 ```
