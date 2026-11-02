# PHP and Twig style baselines

Measured on 2026-11-02 with the GLPI/PHP versions configured in the security
workflow. PHP-CS-Fixer counts **files with fixable formatting changes**, not
individual rule violations.

## PHP-CS-Fixer — GLPI @PER-CS3x0

| GLPI | PHP | PHP-CS-Fixer | Files needing formatting |
|---|---:|---:|---:|
| 11.0 | 8.3 | 3.94.2 | 26 / 27 |
| 12.0 | 8.5 | 3.95.27 | 26 / 27 |

CI fails if more than 26 files need formatting. Changed runtime PHP files are
checked separately and must have zero fixable changes.

## TwigCS

The main templates pass the GLPI TwigCS rules. The dashboard template has
82 existing formatting findings on both GLPI 11 and GLPI 12. CI audits the
dashboard and fails if its total exceeds 82.

These are formatting findings, not a waiver for new or changed templates.
Reduce the dashboard baseline in focused, reviewable changes.
