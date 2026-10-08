# Releasing ConsultDesk

Every release is a zip that installations can install themselves (admin → System → Updates), so
releases are **signed**. An installation installs a zip only if its signature verifies against one
of the public keys in [`api/src/Updates/ReleaseKey.php`](../api/src/Updates/ReleaseKey.php).

## Making a release

1. Bump `api/src/Version.php` (`CURRENT`) in a PR and merge it.
2. Tag the merge commit on `main` and push the tag:

   ```bash
   git checkout main && git pull
   git tag -a v0.9.1 -m "ConsultDesk 0.9.1"
   git push origin v0.9.1
   ```

3. The **Release** workflow then:
   - `zip`: builds `consultdesk-<version>.zip` with read-only access (checks the tag matches
     `Version::CURRENT`),
   - `sign`: in the `release-signing` environment, signs a manifest of the version and the zip's
     SHA-256 with `RELEASE_SIGNING_KEY`, and checks the signature against `ReleaseKey::PUBLIC_KEYS`
     (so a wrong key fails the release instead of producing updates nobody can install),
   - `publish`: creates the GitHub release with the zip and `consultdesk-<version>.zip.sig`.
4. Installations see it within 12 hours (or at once with **Check for updates**).

**Pre-releases:** a version with a suffix (`1.0.0-beta.1`), or a release marked "pre-release" on
GitHub, is offered only to sites with `'UPDATE_CHANNEL' => 'beta'` in `config.php`.

## Who can release

- The signing key is a secret of the **`release-signing`** environment (Settings → Environments),
  which only `v*` **tags** may use: branches, pull requests and manual runs can't read it.
- The **"Protect release tags"** ruleset (Settings → Rules) lets only repository admins create, move
  or delete `v*` tags.
- For an extra check before each signing, add yourself as a **required reviewer** on the
  `release-signing` environment: every release then waits for your approval in the Actions tab.

## The signing key

- Ed25519 (libsodium). The public key is in `ReleaseKey::PUBLIC_KEYS`; the private key is the
  `RELEASE_SIGNING_KEY` environment secret.
- **Backup:** the maintainer keeps the private key offline (it was generated into
  `~/.consultdesk/release-signing.txt`, line 1 = private, line 2 = public). Store it in a password
  manager; never commit it or put it on a server.
- **If the private key is lost:** generate a new pair, put the new public key in
  `ReleaseKey::PUBLIC_KEYS` (replacing the old one) and the new private key in the environment.
  Installed sites can't verify releases signed with the new key, so update each of them **by hand**
  once (DEPLOY-HOSTINGER.md → "By hand"); after that, in-app updates work again.
- **Rotating (or if the key may have leaked):**
  1. Generate a new pair:

     ```bash
     php -r '$k = sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k)), "\n", base64_encode(sodium_crypto_sign_publickey($k)), "\n";'
     ```

  2. Release a version that **adds** the new public key to `ReleaseKey::PUBLIC_KEYS`, still signed
     with the old key. Installations accept it and from then on trust both keys.
  3. Put the new private key in the `release-signing` environment.
  4. Release a version that **removes** the old public key, signed with the new key.
  If the old key leaked, do steps 2–4 quickly and tell site owners to update.

## Generating the very first key

Done once (for 0.9.0):

```bash
php -r '$k = sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k)), "\n", base64_encode(sodium_crypto_sign_publickey($k)), "\n";' > ~/.consultdesk/release-signing.txt
sed -n 1p ~/.consultdesk/release-signing.txt | gh secret set RELEASE_SIGNING_KEY --env release-signing
```
