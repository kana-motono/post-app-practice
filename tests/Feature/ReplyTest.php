<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    private Category $category;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'ポスト主']);
        $this->other = User::factory()->create(['name' => '別の人']);
        $this->category = Category::create(['name' => '雑記']);
        $this->post = Post::create([
            'user_id' => $this->owner->id,
            'category_id' => $this->category->id,
            'title' => '詳細テスト用のタイトル',
            'content' => '詳細テスト用の本文',
        ]);
    }

    private function makeReply(User $user, string $content, ?string $createdAt = null, ?Post $post = null): Reply
    {
        $reply = new Reply([
            'post_id' => ($post ?? $this->post)->id,
            'user_id' => $user->id,
            'content' => $content,
        ]);

        if ($createdAt !== null) {
            $reply->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        }

        $reply->save();

        return $reply;
    }

    private function sendReply(User $user, string $content)
    {
        return $this->actingAs($user)->post(route('replies.store', $this->post), [
            'content' => $content,
        ]);
    }

    // ---- 正常系 ----

    public function test_ポスト詳細にポストとリプライが表示される(): void
    {
        $this->makeReply($this->other, 'ひとことリプライです');

        $this->actingAs($this->owner)
            ->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertSee('詳細テスト用のタイトル')
            ->assertSee('詳細テスト用の本文')
            ->assertSee('ひとことリプライです');
    }

    public function test_リプライは古い順に並ぶ(): void
    {
        // 作成順とは逆の日時を付けて、created_at で並んでいることを確かめる
        $this->makeReply($this->other, '三番目のリプライ', '2026-01-03 00:00:00');
        $this->makeReply($this->owner, '一番目のリプライ', '2026-01-01 00:00:00');
        $this->makeReply($this->other, '二番目のリプライ', '2026-01-02 00:00:00');

        $this->actingAs($this->owner)
            ->get(route('posts.show', $this->post))
            ->assertSeeInOrder(['一番目のリプライ', '二番目のリプライ', '三番目のリプライ']);
    }

    public function test_リプライが0件のときは案内が表示される(): void
    {
        $this->actingAs($this->owner)
            ->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertSee('まだリプライがありません。');
    }

    public function test_ほかのポストのリプライは表示されない(): void
    {
        $another = Post::create([
            'user_id' => $this->owner->id,
            'category_id' => $this->category->id,
            'title' => '別のポスト',
            'content' => '別のポストの本文',
        ]);
        $this->makeReply($this->other, '別のポストへのリプライ', null, $another);

        $this->actingAs($this->owner)
            ->get(route('posts.show', $this->post))
            ->assertDontSee('別のポストへのリプライ');
    }

    public function test_本文を入れて送信するとリプライが増える(): void
    {
        $this->sendReply($this->other, 'よろしくお願いします')
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseCount('replies', 1);
        $this->assertDatabaseHas('replies', [
            'post_id' => $this->post->id,
            'user_id' => $this->other->id,
            'content' => 'よろしくお願いします',
        ]);

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertSee('よろしくお願いします');
    }

    public function test_140字ちょうどなら送信できる(): void
    {
        $content = str_repeat('あ', 140);

        $this->sendReply($this->other, $content)
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseHas('replies', ['content' => $content]);
    }

    public function test_ブラウザ由来の改行コードは1字として数える(): void
    {
        // 「あ」+ 改行 + 「い」138字 = 140字（CRLF のまま数えると141字になってしまう）
        $this->sendReply($this->other, "あ\r\n".str_repeat('い', 138))
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseHas('replies', ['content' => "あ\n".str_repeat('い', 138)]);
    }

    public function test_自分のリプライは削除できる(): void
    {
        $reply = $this->makeReply($this->other, '消したいリプライ');

        $this->actingAs($this->other)
            ->delete(route('replies.destroy', $reply))
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseMissing('replies', ['id' => $reply->id]);
    }

    // ---- 異常系 ----

    public function test_本文が空だとエラーになりリプライは増えない(): void
    {
        $this->sendReply($this->other, '')
            ->assertSessionHasErrors(['content' => 'リプライを入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_空白や改行だけでもエラーになりリプライは増えない(): void
    {
        $this->sendReply($this->other, "  \r\n  ")
            ->assertSessionHasErrors(['content' => 'リプライを入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_141字だとエラーになりリプライは増えない(): void
    {
        $this->sendReply($this->other, str_repeat('あ', 141))
            ->assertSessionHasErrors(['content' => 'リプライは140字以内で入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_本文が配列だと500にならず文字列エラーになりリプライは増えない(): void
    {
        $this->actingAs($this->other)
            ->post(route('replies.store', $this->post), ['content' => ['あ']])
            ->assertSessionHasErrors(['content' => 'リプライは文字列で入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_エラー後の画面にメッセージと入力済みの本文が表示される(): void
    {
        $tooLong = str_repeat('あ', 140).'オーバー';

        $this->actingAs($this->other)
            ->from(route('posts.show', $this->post))
            ->followingRedirects()
            ->post(route('replies.store', $this->post), ['content' => $tooLong])
            ->assertOk()
            ->assertSee('リプライは140字以内で入力してください。')
            ->assertSee($tooLong);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_空で送信したときも同じ画面にエラーが表示される(): void
    {
        $this->actingAs($this->other)
            ->from(route('posts.show', $this->post))
            ->followingRedirects()
            ->post(route('replies.store', $this->post), ['content' => ''])
            ->assertOk()
            ->assertSee('リプライを入力してください。');
    }

    public function test_他人のリプライは削除できない(): void
    {
        $reply = $this->makeReply($this->other, '他人のリプライ');

        $this->actingAs($this->owner)
            ->delete(route('replies.destroy', $reply))
            ->assertForbidden();

        $this->assertDatabaseHas('replies', ['id' => $reply->id]);
    }

    public function test_存在しないポストは404になる(): void
    {
        $this->actingAs($this->owner)
            ->get(route('posts.show', 99999))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('replies.store', 99999), ['content' => 'どこにも届かない'])
            ->assertNotFound();

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_存在しないリプライの削除は404になる(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('replies.destroy', 99999))
            ->assertNotFound();
    }

    public function test_未ログインはログイン画面へリダイレクトされる(): void
    {
        $reply = $this->makeReply($this->other, 'ログインしていると見えるリプライ');

        $this->get(route('posts.show', $this->post))->assertRedirect(route('login'));
        $this->post(route('replies.store', $this->post), ['content' => 'こんにちは'])
            ->assertRedirect(route('login'));
        $this->delete(route('replies.destroy', $reply))->assertRedirect(route('login'));

        // 増えても減ってもいない（最初の1件のまま）
        $this->assertDatabaseCount('replies', 1);
    }

    // ---- 表示の出し分け ----

    public function test_自分のリプライには削除ボタンが出る(): void
    {
        $reply = $this->makeReply($this->other, '自分のリプライ');

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertSee('action="'.route('replies.destroy', $reply).'"', false);
    }

    public function test_他人のリプライには削除ボタンが出ない(): void
    {
        $reply = $this->makeReply($this->other, '他人のリプライ');

        $this->actingAs($this->owner)
            ->get(route('posts.show', $this->post))
            ->assertSee('他人のリプライ')
            ->assertDontSee('action="'.route('replies.destroy', $reply).'"', false);
    }

    public function test_ポスト所有者でも他人のリプライの削除ボタンは出ない(): void
    {
        $reply = $this->makeReply($this->other, 'ポスト主から見える他人のリプライ');

        $this->actingAs($this->owner)
            ->get(route('posts.show', $this->post))
            ->assertDontSee('action="'.route('replies.destroy', $reply).'"', false);

        $this->actingAs($this->owner)
            ->delete(route('replies.destroy', $reply))
            ->assertForbidden();
    }

    // ---- 回帰（変えないもの） ----

    public function test_タイムラインのタイトルが詳細画面へのリンクになっている(): void
    {
        $this->actingAs($this->other)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('href="'.route('posts.show', $this->post).'"', false)
            ->assertSee('詳細テスト用のタイトル');
    }

    public function test_タイムラインの編集と削除ボタンの出し分けは従来どおり(): void
    {
        $editLink = 'href="'.route('posts.edit', $this->post).'"';
        $deleteForm = 'action="'.route('posts.destroy', $this->post).'"';

        $this->actingAs($this->owner)
            ->get(route('posts.index'))
            ->assertSee($editLink, false)
            ->assertSee($deleteForm, false);

        $this->actingAs($this->other)
            ->get(route('posts.index'))
            ->assertDontSee($editLink, false)
            ->assertDontSee($deleteForm, false);
    }

    public function test_リプライ付きのポストを削除するとリプライも消える(): void
    {
        $reply = $this->makeReply($this->other, 'ポストと一緒に消えるリプライ');

        $this->actingAs($this->owner)
            ->delete(route('posts.destroy', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseMissing('posts', ['id' => $this->post->id]);
        $this->assertDatabaseMissing('replies', ['id' => $reply->id]);
    }

    public function test_ユーザーを削除するとそのユーザーのリプライも消える(): void
    {
        $mine = $this->makeReply($this->other, '別の人のリプライ');
        $theirs = $this->makeReply($this->owner, 'ポスト主のリプライ');

        $this->other->delete();

        $this->assertDatabaseMissing('replies', ['id' => $mine->id]);
        $this->assertDatabaseHas('replies', ['id' => $theirs->id]);
    }
}
