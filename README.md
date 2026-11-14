
## i18n
After adding strings: `wp i18n make-pot . languages/invoqk.pot --domain=invoqk --exclude=vendor,tests,docs --skip-js`, translate `languages/invoqk-de_DE.po`, then `msgfmt -o languages/invoqk-de_DE.mo languages/invoqk-de_DE.po`.
