# Gates and tests for this package.
#
# `arch-check` is the workspace architecture gate (docs/plans/50-program/06-gates.md):
# a library has no WordPress symbols in `src/` - no hooks, no options API, no
# `$wpdb`, no `ABSPATH` guard. `test` depends on it, so a package cannot pass its
# own run while reaching for the site API. Debt, if any, is declared per file in
# `arch-allow.txt` with a card from docs/plans/50-program/11-arch-debt.md.
.PHONY: test arch-check install

install:
	composer install

test: arch-check
	vendor/bin/phpunit

arch-check:
	@python3 "$(CURDIR)/../../../tools/arch-check.py" "$(CURDIR)"
