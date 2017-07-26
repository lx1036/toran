# How to create private packages programmatically?

Two options here:

- For an initial setup, the best is probably to edit the app/toran/config.yml file manually,
  adding repositories like:

  ```
  repositories:
    - { type: vcs, url: 'git@example.org:seldaek/repo.git' }
    - { type: vcs, url: 'git@example.org:seldaek/repo2.git' }
    # ...
  ```

- If you then want to automate it further, you can use the API:

  ```
  curl -XPOST http://toran.example.com/repositories/add \
      -H 'Content-Type: application/json' \
      -d '{"type":"vcs","url":"https://github.com/Seldaek/monolog"}'

  {"status":"success","message":"Package created","location":"http://toran.example.com/repositories/2-0e78b2a103fa8b4ec68b6e337d79731ce1ea2e12"}
  ```
