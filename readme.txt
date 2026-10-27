=== Kashiwazaki Pattern Lock ===
Contributors: tsuyoshikashiwazaki
Tags: login, pattern lock, security, authentication, two-factor
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress標準のログイン画面に、9点をなぞるパターンロック認証を追加するプラグインです。

== Description ==

WordPress 標準のログイン画面（wp-login.php）に、3×3 の 9 点からなるパターン入力欄を追加します。ユーザー名・パスワード・パターンのすべてが正しいときだけログインを許可します。

= 主な機能 =

* 標準ログイン画面にパターン入力欄を自動表示（ショートコード不要）
* PC はマウス、スマートフォン・タブレットは指でなぞって入力
* 選んだ点と、点を結ぶ線を表示
* 入力したパターンを消して入力し直せる（「入力を消す」ボタン / Esc キー）
* キーボードでも入力できる（矢印キーで移動、Enter / Space で選択、Backspace で 1 点戻す）
* パターンは 4 点以上。同じ点の重複不可。飛び越えた点は自動で補完（Android と同じ規則）
* パターンの設定・変更は確認のため 2 回入力
* 管理者（manage_options）だけが設定・変更できる
* パターンは平文で保存せず、wp_hash_password()（bcrypt）でハッシュ化
* 正誤は必ずサーバー側で検証
* 連続失敗で一定時間ログインを制限（回数・時間は管理画面で設定）
* 設定保存は権限確認と nonce 検証
* パターンは POST で送り、URL やログには残さない
* 有効化直後はパターン未設定のため、ログインを妨げない
* プラグイン一覧に設定画面へのリンクを表示

= 対応範囲 =

パターン認証が働くのは WordPress 標準のログイン画面だけです。REST API・アプリケーションパスワード・XML-RPC・パスワード再設定・保護された投稿のパスワード入力は対象外で、これまでどおりユーザー名とパスワードだけで認証されます。ログイン済みの認証 Cookie も対象外です。詳しくは README.md を参照してください。

= 復旧方法 =

パターンを忘れた場合や設定を誤った場合は、FTP などでプラグインのフォルダ名を変更して無効化してください。WordPress 標準のログインに戻ります。手順の詳細は README.md の「復旧手順」を参照してください。

== Installation ==

1. `wp-plugin-kashiwazaki-pattern-lock` フォルダを `wp-content/plugins/` にアップロードします。
2. 管理画面の「プラグイン」一覧で **Kashiwazaki Pattern Lock** を有効化します。
3. 有効化すると **初期設定画面** が自動で開きます（開かない場合は左メニューの **Kashiwazaki Pattern Lock**、またはプラグイン一覧の「初期設定」リンクから開けます）。
4. 「1. 新しいパターン」と「2. 確認用パターン」に同じパターンを 2 回入力し、「パターンを保存して開始」を押します。

== Frequently Asked Questions ==

= パターンを忘れてログインできません =

FTP などで `wp-plugin-kashiwazaki-pattern-lock` フォルダの名前を変更すると、WordPress がプラグインを自動的に無効化し、標準ログインで入れるようになります。手順は README.md の「復旧手順」を参照してください。

= 有効化したのにパターン欄が出ません =

パターンが未設定です。管理メニューの **Kashiwazaki Pattern Lock** でパターンを保存すると、ログイン画面に表示されます。

= REST API や XML-RPC はパターンが必要ですか =

いいえ。本プラグインが検証するのは標準ログイン画面だけです。REST API・アプリケーションパスワード・XML-RPC は対象外です。

= パターンを何点にすればよいですか =

4 点以上が設定できます。6 点以上を推奨します。4 点は組み合わせが少なく、総当たりに弱いためです。

= 管理画面のメニューはどこにありますか =

管理メニューの位置 81 に **Kashiwazaki Pattern Lock** として表示されます。プラグイン一覧の「設定」リンクからも開けます。

== Screenshots ==

1. ログイン画面に表示されるパターン入力欄
2. 管理画面の設定ページ

== Changelog ==

= 1.0.0 =
* 初版。
* 標準ログイン画面への 9 点パターン入力欄の追加。
* 管理画面でのパターン設定・変更・解除。
* サーバー側でのパターン検証（bcrypt ハッシュ）。
* 連続失敗によるログイン制限（回数・時間の設定）。
* プラグイン一覧の設定リンク、未設定時の案内。

== Upgrade Notice ==

= 1.0.0 =
初版です。
