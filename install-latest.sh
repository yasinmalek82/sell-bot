#!/usr/bin/env bash
set -Eeuo pipefail

REPO="${NEO_REPO:-yasinmalek82/sell-bot}"
VERSION="${NEO_VERSION:-}"

if [[ ${EUID} -ne 0 ]]; then
  echo 'Run this installer as root (sudo).' >&2
  exit 1
fi

[[ $REPO =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || {
  echo 'NEO_REPO must use the OWNER/REPOSITORY format.' >&2
  exit 1
}

if [[ -z $VERSION ]]; then
  VERSION="$(
    curl -fsSL \
      -H 'Accept: application/vnd.github+json' \
      -H 'X-GitHub-Api-Version: 2022-11-28' \
      "https://api.github.com/repos/${REPO}/tags?per_page=100" \
      | sed -nE 's/.*"name":[[:space:]]*"(v[0-9]+\.[0-9]+\.[0-9]+)".*/\1/p' \
      | sort -V \
      | tail -n 1
  )"
fi

[[ $VERSION =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
  echo 'No valid stable release tag was found.' >&2
  exit 1
}

TEMP_DIR="$(mktemp -d /tmp/neo-bot-installer.XXXXXX)"
trap 'rm -rf -- "$TEMP_DIR"' EXIT
INSTALLER="$TEMP_DIR/install.sh"

echo "Downloading Neo Bot ${VERSION}..."
curl -fsSL \
  "https://raw.githubusercontent.com/${REPO}/${VERSION}/install.sh" \
  -o "$INSTALLER"
bash -n "$INSTALLER"

echo "Starting Neo Bot ${VERSION} installer..."
MIRZA_REPO="$REPO" MIRZA_BRANCH="$VERSION" bash "$INSTALLER" "$@"
