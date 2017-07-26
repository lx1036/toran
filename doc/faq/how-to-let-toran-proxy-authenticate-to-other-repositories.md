# How to let Toran Proxy authenticate to other repositories?

## Git / SSH authentication

SSH auth is handled at the system level and you should make sure that whichever user
Toran runs as has the required credentials in ~/.ssh/.

## GitHub, GitLab and HTTP authentication

For HTTP-level authentication as this is all handled via Composer internally, you
only need to configure the Composer auth.json values. This file is located at
`app/toran/composer/auth.json`.

It just looks like a regular auth.json, and you would have things like this:

```
{
    "github-oauth": {
        "github.com": "oauth-token-here",
        "ghe.yourhost.com": "oauth-token-here"
    },
    "gitlab-oauth": {
        "gitlab.yourhost.com": "oauth-token-here"
    },
    "http-basic": {
        "repo.example1.org": {
            "username": "my-username1",
            "password": "my-secret-password1"
        },
        "repo.example2.org": {
            "username": "my-username2",
            "password": "my-secret-password2"
        }
    }
}
```
