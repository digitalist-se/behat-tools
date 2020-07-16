# behat-tools
Behat related tools

## Set up

Add this to the `repositories` section of your `composer.json`

```
        {
            "type": "vcs",
            "url": "https://github.com/digitalist-se/behat-tools"
        },
```

Execute:
```
composer require digitalist-se/behat-tools
```

Add to your `behat.yml`:
```
default:
  suites:
    default:
      contexts:
        - digitalistse\BehatTools\Context\CommonContext
```
Add more Contexts depending on your needs using the same structure.
