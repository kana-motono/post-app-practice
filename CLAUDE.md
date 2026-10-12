# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

A small Laravel 10 post app (list / edit / delete posts, with Fortify auth) used as the teaching subject for Tutorials 13–15. Docs, comments, and UI text are in Japanese — keep new user-facing text and comments in Japanese to match.

## Commands

Everything runs through Laravel Sail (Docker); there is no local PHP/MySQL setup. On Windows, run from the WSL terminal.

```bash
./vendor/bin/sail up -d                      # start containers (app at http://localhost)
./vendor/bin/sail down                       # stop
./vendor/bin/sail artisan migrate --seed     # create tables + practice data
./vendor/bin/sail artisan migrate:fresh --seed  # reset DB
./vendor/bin/sail artisan test               # run all tests
./vendor/bin/sail artisan test --filter=SomeTest          # single test class/method
./vendor/bin/sail artisan test tests/Feature/SomeTest.php # single file
./vendor/bin/sail pint                       # format (Laravel Pint)
```

- If the first `migrate` fails with "Connection refused", MySQL is still starting — wait and retry.
- Tests run against MySQL using a separate `testing` database (set in `phpunit.xml`; Sail's MySQL container creates it on first boot). They are not SQLite/in-memory.
- Practice accounts after seeding: `usera@example.com` / `userb@example.com`, password `password`. Each owns 2 posts (A: `/posts/1`, `/posts/2`; B: `/posts/3`, `/posts/4`). Seeded categories: お知らせ・技術メモ・雑記.

## Architecture

- **Auth is Laravel Fortify, not Breeze/Jetstream.** Fortify registers the login/register/logout/password-reset routes itself, so they are not in `routes/web.php`. Views are mapped in `app/Providers/FortifyServiceProvider.php` (`auth.login`, `auth.register`); user creation logic is in `app/Actions/Fortify/`. Enabled features are toggled in `config/fortify.php` (registration and password reset only). After login, users go to `/posts` (`fortify.home` and `RouteServiceProvider::HOME`).
- **Posts:** all `/posts` routes live in an `auth` middleware group in `routes/web.php` and go to `PostController`. Routes are index, show, store, edit, update, destroy. There is no separate create page: the post form sits at the top of `posts/index.blade.php` (the topic select starts on an empty "選択してください" option, so it must be chosen). `store` sets `user_id` from `$request->user()->id` on the server and never reads it from the request.
- **Authorization:** `app/Policies/PostPolicy.php` (owner-only `update`/`delete`) is **auto-discovered**. `store` has no policy method: the `auth` middleware group is the only gate, since any logged-in user may post. `AuthServiceProvider::$policies` is empty on purpose. The controller calls `$this->authorize(...)`, which returns 403 for non-owners, and `posts/index.blade.php` hides the buttons with `@can`/`@canany`. When you add an action, update the policy, the controller, and the view together.
- **Data model:** `User` 1–n `Post` n–1 `Category`. `posts.user_id` cascades on user delete; `posts.category_id` does not cascade. Validation is inline in the controller (`$request->validate`), not in FormRequests; `store` and `update` share it via the private `PostController::validatedPostData` (title ≤255, content ≤140 after CRLF→LF, `category_id` must be an integer that exists).
- **Views** are standalone Blade files with inline `<style>`. There is no shared layout and no Vite/`@vite` usage, even though `vite.config.js`/`package.json` exist. Node is not needed to run the app.

## アプリの前提（依頼のたびに書かなくてよいもの）

依頼文に書かれていなくても、次の現状を前提にし、今ある動き（一覧・編集・削除）を壊さないようにする。機能の追加や、依頼された変更は妨げない。既存の動きが変わってしまう場合だけ、その旨を伝える。

- **タイムライン（`/posts`）の動き:** 全ユーザーのポストを新しい順に表示する（同時刻は id の大きい方が先）。投稿フォームは一覧の上部にあり、本文の残り文字数を表示する。ポストのタイトルから `/posts/{post}`（詳細・リプライ）へ移動できる。編集・削除ボタンは投稿者本人にだけ表示し、投稿者以外は操作できない（403）。
- **リプライ:** 詳細ページに古い順（同時刻は id 順）で表示する。
- **入力チェックのメッセージは日本語で出す。** アプリのロケールは `en` で `lang/` も無いため、何も指定しないと Laravel 標準の英語メッセージが出る。新しい入力チェックには、`validate` の第2引数で日本語メッセージを必ず指定し、既存の文言（例: `本文は140字以内で入力してください。`、`リプライは140字以内で入力してください。`）に口調を合わせる。
- 本文・リプライの140字は、改行を CRLF→LF に揃えてから数える。

## 実装とテストの進め方

- 実装を頼まれたら、テストも書く（`tests/Feature/`、既存のテストの書き方に合わせる）。
- `./vendor/bin/sail test` で全テストを回し、落ちたら通るまで直す。
- 最後に、実行したコマンドと結果（通った件数・落ちたテスト）を報告する。回せなかった場合は、回せなかったと理由を書く。

## PRの書き方

PR本文には次の2つを書く。

- **テスト結果:** 実行したコマンドと、通った／落ちた件数。
- **受け入れ条件との突き合わせ:** 条件を1つずつ挙げ、満たしているかと、その根拠（テスト名・該当ファイル）を対応させる。満たしていない条件があれば、そのまま書く。

## Repository notes

- `answers/` holds Tutorial 13 model answers for the design documents (Markdown + draw.io). It is **not** part of the app and has no effect on its behavior. Don't read or change it when working on app features.
- Learners write their own design docs in `docs/` (created during Tutorial 13, e.g. `docs/13-3/`). It may not exist yet.
