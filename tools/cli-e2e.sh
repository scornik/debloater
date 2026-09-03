#!/bin/sh
# The whole MVP loop through the real `wp` binary on the fixture site.
#
# The PHPUnit suite drives the command classes directly, which covers every
# branch but never touches WP-CLI's own argument parsing, its JSON output, or a
# real loopback HTTP request. This does all three, on a site whose data is
# committed rather than wrapped in a test transaction.
#
# Run it with:  npm run test:cli
#
# BUILD-SPEC §17 Phase 7: "whole MVP loop runnable from CLI on the fixture site".

set -eu

WP="wp --allow-root --skip-themes"
FAIL=0

say() {
	printf '\n\033[1m%s\033[0m\n' "$1"
}

# Run a command and check its exit code, without letting `set -e` stop us.
expect_code() {
	expected="$1"
	shift

	set +e
	# shellcheck disable=SC2086 # Word splitting is how the command is assembled.
	output=$( "$@" 2>&1 )
	actual=$?
	set -e

	if [ "$actual" -eq "$expected" ]; then
		printf '  ok   (exit %s)  %s\n' "$actual" "$*"
	else
		printf '  FAIL (exit %s, wanted %s)  %s\n%s\n' "$actual" "$expected" "$*" "$output"
		FAIL=1
	fi
}

expect_json() {
	label="$1"
	shift

	set +e
	output=$( "$@" 2>/dev/null )
	code=$?
	set -e

	if [ "$code" -ne 0 ]; then
		printf '  FAIL (exit %s)  %s\n' "$code" "$label"
		FAIL=1
		return
	fi

	if printf '%s' "$output" | php -r 'exit( null === json_decode( stream_get_contents( STDIN ), true ) ? 1 : 0 );'; then
		printf '  ok   (valid JSON)  %s\n' "$label"
	else
		printf '  FAIL (not JSON)  %s\n%s\n' "$label" "$output"
		FAIL=1
	fi
}

say 'Starting from a clean slate'
# The fixture site keeps its state between runs, so the plugin may or may not
# already be active and there may or may not be anything to undo. Neither is a
# failure; both have to be true before the loop starts.
$WP plugin activate debloater >/dev/null 2>&1 || true
$WP debloater rollback --yes >/dev/null 2>&1 || true

say 'scan'
expect_json 'scan --json' $WP debloater scan --json

say 'findings'
expect_json 'findings --json' $WP debloater findings --json
expect_code 1 $WP debloater findings --risk=nonsense

say 'preview'
expect_json 'preview --json' $WP debloater preview --profile=safe --json
expect_code 1 $WP debloater preview --tweaks=core.not_a_real_tweak

say 'apply requires confirmation'
expect_code 1 $WP debloater apply --profile=safe

say 'apply'
# 0 when the site verifies cleanly, 3 when the checks could not all run. Both
# mean the change is in place; 2 would mean it was undone.
set +e
$WP debloater apply --profile=safe --yes
APPLY_CODE=$?
set -e

case "$APPLY_CODE" in
	0|3) printf '  ok   (exit %s)  apply --profile=safe --yes\n' "$APPLY_CODE" ;;
	*)   printf '  FAIL (exit %s)  apply --profile=safe --yes\n' "$APPLY_CODE" ; FAIL=1 ;;
esac

say 'status after applying'
STATUS=$( $WP debloater status --json )

if printf '%s' "$STATUS" | grep -q '"present": true'; then
	printf '  ok   runtime is in place\n'
else
	printf '  FAIL runtime is not in place after a successful apply\n%s\n' "$STATUS"
	FAIL=1
fi

say 'verify'
set +e
$WP debloater verify >/dev/null 2>&1
VERIFY_CODE=$?
set -e

case "$VERIFY_CODE" in
	0|3) printf '  ok   (exit %s)  verify\n' "$VERIFY_CODE" ;;
	*)   printf '  FAIL (exit %s)  verify\n' "$VERIFY_CODE" ; FAIL=1 ;;
esac

say 'snapshots'
expect_json 'snapshots list --json' $WP debloater snapshots list --json

say 'export and import'
EXPORT_FILE=/tmp/debloater-e2e.json
expect_code 0 $WP debloater export --file="$EXPORT_FILE"
expect_code 0 $WP debloater import "$EXPORT_FILE"
expect_code 1 $WP debloater import /tmp/definitely-not-here.json

say 'rollback requires confirmation'
expect_code 1 $WP debloater rollback

say 'rollback'
expect_code 0 $WP debloater rollback --yes

STATUS=$( $WP debloater status --json )

if printf '%s' "$STATUS" | grep -q '"selection_count": 0'; then
	printf '  ok   the site is back to nothing selected\n'
else
	printf '  FAIL the rollback left changes behind\n%s\n' "$STATUS"
	FAIL=1
fi

rm -f "$EXPORT_FILE"

if [ "$FAIL" -eq 0 ]; then
	say 'The whole loop ran on the fixture site.'
	exit 0
fi

say 'Something in the loop failed.'
exit 1
