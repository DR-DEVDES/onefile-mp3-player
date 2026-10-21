# Security Policy

## Reporting

Please avoid publishing exploitable details in public issues. Contact the repository owner
privately through GitHub's security reporting feature if it is enabled.

## Deployment guidance

The player exposes audio files and directory names under its installation directory.
Restrict access with web-server authentication or network controls when the library is private.
Do not treat path validation as a replacement for server permissions.
