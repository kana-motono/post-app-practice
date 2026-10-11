<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '投稿者A']);
        $this->other = User::factory()->create(['name' => '別の人']);
        $this->category = Category::create(['name' => '雑記']);
    }

    private function makePost(User $user, string $title, ?string $createdAt = null): Post
    {
        $post = new Post([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'content' => $title.'の本文',
        ]);

        if ($createdAt !== null) {
            $post->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        }

        $post->save();

        return $post;
    }

    private function store(array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->from(route('posts.index'))
            ->post(route('posts.store'), array_merge([
                'title' => '新しいタイトル',
                'category_id' => $this->category->id,
                'content' => '新しい本文',
            ], $overrides));
    }

    // 受け入れ条件1: タイムラインに書く場所がある

    public function test_タイムラインに投稿フォームが表示される(): void
    {
        $this->makePost($this->other, '既存ポスト');

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('action="'.route('posts.store').'"', false)
            ->assertSee('name="title"', false)
            ->assertSee('name="category_id"', false)
            ->assertSee('name="content"', false)
            ->assertSee('id="content-counter"', false)
            ->assertSee('あと140字');
    }

    public function test_ポストが0件でも投稿フォームが表示される(): void
    {
        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('まだポストがありません。')
            ->assertSee('action="'.route('posts.store').'"', false);
    }

    // 受け入れ条件2: 送るとタイムラインの先頭に増える

    public function test_投稿するとポストが1件増え先頭に表示される(): void
    {
        $this->makePost($this->other, '古いポスト', '2024-01-01 00:00:00');

        $this->store(['title' => '先頭に出るタイトル'])
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseHas('posts', [
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => '先頭に出るタイトル',
            'content' => '新しい本文',
        ]);

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSeeInOrder(['先頭に出るタイトル', '古いポスト']);
    }

    public function test_投稿者はログイン中のユーザーになる(): void
    {
        $this->store(['user_id' => $this->other->id])
            ->assertRedirect(route('posts.index'));

        $post = Post::firstOrFail();
        $this->assertSame($this->user->id, $post->user_id);
    }

    // 受け入れ条件3: 本文が空だとエラーになりポストは増えない

    public function test_本文が空だとエラーになりポストは増えない(): void
    {
        $this->store(['content' => ''])
            ->assertRedirect(route('posts.index'))
            ->assertSessionHasErrors('content');

        $this->assertDatabaseCount('posts', 0);

        $this->actingAs($this->user)
            ->followingRedirects()
            ->from(route('posts.index'))
            ->post(route('posts.store'), [
                'title' => '新しいタイトル',
                'category_id' => $this->category->id,
                'content' => '',
            ])
            ->assertSee('class="error"', false);
    }

    public function test_タイトルが空だとエラーになりポストは増えない(): void
    {
        $this->store(['title' => ''])->assertSessionHasErrors('title');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_存在しないトピックだとエラーになりポストは増えない(): void
    {
        $this->store(['category_id' => 9999])->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_トピックが配列だと500にならずエラーになりポストは増えない(): void
    {
        $this->store(['category_id' => [$this->category->id]])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_編集でトピックが配列だと500にならずエラーになり更新されない(): void
    {
        $post = $this->makePost($this->user, '元のタイトル');

        $this->actingAs($this->user)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), [
                'title' => '更新後のタイトル',
                'category_id' => [$this->category->id],
                'content' => '更新後の本文',
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame('元のタイトル', $post->fresh()->title);
        $this->assertSame($this->category->id, $post->fresh()->category_id);
    }

    public function test_トピック欄は空の選択肢から始まる(): void
    {
        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSee('<option value="">選択してください</option>', false)
            ->assertSee('<option value="'.$this->category->id.'"', false);
    }

    public function test_トピックを選ばずに送るとエラーになりポストは増えない(): void
    {
        $this->store(['category_id' => ''])->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_トピックの項目自体がなくてもエラーになりポストは増えない(): void
    {
        $this->actingAs($this->user)
            ->from(route('posts.index'))
            ->post(route('posts.store'), ['title' => 'タイトル', 'content' => '本文'])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_140字ちょうどなら投稿できる(): void
    {
        $content = str_repeat('あ', 140);

        $this->store(['content' => $content])->assertRedirect(route('posts.index'));

        $this->assertSame($content, Post::firstOrFail()->content);
    }

    public function test_141字だとエラーになりポストは増えない(): void
    {
        $this->store(['content' => str_repeat('あ', 141)])
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_ブラウザ由来の改行コードは1字として数える(): void
    {
        // 「あ」+ 改行 + 「い」138字 = 140字（CRLF のまま数えると141字になってしまう）
        $this->store(['content' => "あ\r\n".str_repeat('い', 138)])
            ->assertRedirect(route('posts.index'));

        $this->assertSame("あ\n".str_repeat('い', 138), Post::firstOrFail()->content);
    }

    public function test_本文が配列だと500にならずエラーになりポストは増えない(): void
    {
        $this->store(['content' => ['あ']])->assertSessionHasErrors('content');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_エラーで戻ると入力内容と残り文字数が保持される(): void
    {
        // 141字 → 1字オーバー
        $content = str_repeat('あ', 141);

        $this->store(['title' => '保持されるタイトル', 'content' => $content])
            ->assertSessionHasErrors('content');

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSee('value="保持されるタイトル"', false)
            ->assertSee($content)
            ->assertSee('1字オーバー');
    }

    // 受け入れ条件4: 自分の名前と編集・削除が出る

    public function test_投稿したポストに自分の名前と編集削除が出る(): void
    {
        $this->store(['title' => '自分のポスト']);
        $post = Post::firstOrFail();

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSee('投稿者A')
            ->assertSee('href="'.route('posts.edit', $post).'"', false)
            ->assertSee('action="'.route('posts.destroy', $post).'"', false);
    }

    public function test_他人のポストには編集削除が出ない(): void
    {
        $post = $this->makePost($this->other, '他人のポスト');

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSee('他人のポスト')
            ->assertDontSee('href="'.route('posts.edit', $post).'"', false)
            ->assertDontSee('action="'.route('posts.destroy', $post).'"', false);
    }

    // 受け入れ条件5: ログアウトすると書く場所にたどり着けない

    public function test_未ログインは投稿できずログイン画面へ飛ばされる(): void
    {
        $this->post(route('posts.store'), [
            'title' => '新しいタイトル',
            'category_id' => $this->category->id,
            'content' => '新しい本文',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_未ログインはタイムラインを開けない(): void
    {
        $this->get(route('posts.index'))->assertRedirect(route('login'));
    }
}
