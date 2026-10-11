<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostContentLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Post $post;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::create(['name' => '雑記']);
        $this->post = Post::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => '元のタイトル',
            'content' => '元の本文',
        ]);
    }

    private function update(string $content)
    {
        return $this->actingAs($this->user)->put(route('posts.update', $this->post), [
            'title' => '元のタイトル',
            'category_id' => $this->category->id,
            'content' => $content,
        ]);
    }

    public function test_140字ちょうどなら更新できる(): void
    {
        $content = str_repeat('あ', 140);

        $this->update($content)->assertRedirect(route('posts.index'));

        $this->assertSame($content, $this->post->fresh()->content);
    }

    public function test_141字だとエラーになり保存されない(): void
    {
        $this->update(str_repeat('あ', 141))
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertSame('元の本文', $this->post->fresh()->content);
    }

    public function test_ブラウザ由来の改行コードは1字として数える(): void
    {
        // 「あ」+ 改行 + 「い」138字 = 140字（CRLF のまま数えると141字になってしまう）
        $this->update("あ\r\n".str_repeat('い', 138))
            ->assertRedirect(route('posts.index'));

        $this->assertSame("あ\n".str_repeat('い', 138), $this->post->fresh()->content);
    }
}
