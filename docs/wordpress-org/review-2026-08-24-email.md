# WordPress.org Plugin Review — runda 2 (otrzymana 2026-08-24)

Review ID: `AUTOPREREVIEW ❗TRM-OWN polytrans/jmarianski/24Aug26/T1 24Aug26/4.2 (P0TDX357593HGN)`
Nadawca: WordPress.org Plugin Directory
Data: 2026-08-24 03:11
Dotyczy: `polytrans.zip` zgłoszonego 2026-08-20 (3 dni i 13 godzin przed recenzją)
Poprzednia runda: `REVIEW-ISSUES.md` (`TRM-DESC ... 15Mar26/3.8`)

> Treść maila zapisana dosłownie (bez elementów graficznych i stopki marketingowej).
> Fragmenty oznaczone ✨ zostały wygenerowane przez AI recenzenta — sam mail o tym informuje.

---

📦 This is a review of the file polytrans.zip submitted 3 days and 13 hours ago. Test it on Playground.

👋 jmarianski - Let's improve your plugin!

Thank you for submitting your plugin, "PolyTrans".

Our volunteer reviewers, tools, and/or AI aids identified issues in your plugin that require your attention.

We've pended your submission to give you a chance to review and fix these common issues.

🤖 Please note that this message was generated using a combination of humans, algorithms, and AI in varying proportions. It may not have been reviewed by a human. All AI outputs are marked with the ✨ emoji. Pay attention to it, it's quite accurate.

## The review process

1. Read this email carefully from start to finish. Review every issue, including the linked documentation and the provided examples. Then search your codebase for other occurrences of the same issues, even if they are not explicitly mentioned in this review. Take the time to understand each issue so you can apply what you learn to your plugin going forward.
2. Address all identified issues, test your plugin thoroughly and upload a corrected version. If you have doubts about a specific issue, fix everything else and ask your questions alongside the update.

   Note: The Plugins Team volunteers are not your developers or QA team. They are here to help you identify and understand issues so that you can improve and maintain your plugin in the future. Finding and fixing these issues remains your responsibility.
3. Reply to this email thread (do not create a new email or reply to the submission confirmation). Your plugin will then be added to your reviewer's queue, and they will send you any remaining issues that are identified.
4. Once no further issues remain, your plugin will be approved 🎉

Fewer review cycles mean quicker approval, while multiple rounds can extend the process to weeks or months.

## Before you reply

Throughout the entire process, and before you reply, please ensure that:

- You have addressed all reported issues and thoroughly tested your plugin. Make sure your updated version does not introduce new issues, such as fatal errors during activation.
- You are replying to this email thread.
- Your reply is brief and to the point. Include only information that is relevant for the next review; there is no need to describe every change you made, and please avoid unnecessary verbosity or AI-generated filler.
- If you wish to alter your permalink (aka the plugin slug) "polytrans", you have explicitly stated your desired permalink in your reply. Changing the display name alone is not sufficient, and permalinks cannot be altered after approval.
- You are making meaningful progress between review rounds. Updates that resolve only a small portion of the reported issues delay the review process, and the plugin may be rejected out of respect for the volunteers' time and other plugin authors waiting in the queue. Plugins rejected under these circumstances will not be reviewed again.
- You treat the review as a learning opportunity and improve your knowledge of development practices and the directory guidelines accordingly.

## Guidelines

In addition to code quality, security and functionality, all plugins must adhere to the guidelines you accepted when submitting this plugin: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/.

### Have you read the guidelines and this plugin complies with them?

Our automated tools have detected patterns that may require a closer look regarding compliance with certain guidelines. We will verify this during our manual review, but it's best to address any potential issues beforehand. In particular, please pay attention to the following:

- Plugins should not hijack the admin dashboard. Upgrade prompts, notices, alerts, and the like must be limited in scope and used with moderation. (Guideline 11)

Please check it, and if you think everything is fine, do not worry. Our tools are very thorough and may highlight different things as potential issues.

### Is the name of this plugin descriptive and distinctive, and does it respect the trademarks and project names of others?

📜 General plugin name/slug requirements

- Plugin names and slugs must not be too generic; they could briefly describe what the plugin does.
- Plugin names and slugs can be original and unique, even if they don't describe the functionality directly — as long as they are recognizable and distinctive.
- Plugin names and slugs must be distinguishable from those of other plugins to avoid confusion.
- Plugin names and slugs must respect others' trademarks and project names. If your plugin includes a trademark or project name that you do not own, you must clearly mention it in a way that denotes that there is no affiliation.

This plugin display name(s) is/are "PolyTrans" and the slug is "polytrans". The AI has detected ✨ "PolyTrans" as potential trademark(s).

We asked a trained AI about common issues with plugin names and it thinks there might be a problem.

✨ "PolyTrans" is a registered trademark owned by other entities and is also very close to an existing AI translation plugin for Polylang.

For example, for your plugin:

- An alternative name may be: ✨ TreeTank Translation Workflow for Polylang
- An alternative slug may be: ✨ treetank-translation-workflow-polylang

ℹ️ These suggestions were made by the AI. Bear in mind that a skilled human, either you or a volunteer from the Plugin Review Team, may spot issues with the new suggested name.

⚠️ As with the rest of the review, you should address this issue and make any necessary changes. Simply changing the name without first checking what the issue is and how you can solve it won't help most of the time. Failure to address any of the issues raised will result in your submission being rejected for failing to demonstrate the expected level of effort in resolving the issues identified during the review process.

☑️ Steps to resolve this issue

Choose a display name and slug that is distinctive, describes your plugin and/or uses a unique name, while respecting others' trademarks and project names, then:

1. Update the display name in both the readme file and plugin headers.
2. Update the slug in your plugin files, for example in the internationalization functions.
3. Reply to this email requesting a new slug reservation.
4. In addition to the changes you have made to your code, we also need to reserve and allocate the new slug that you have chosen for your submission.
5. Upload a new version via the "Add your plugin" page.
6. If you are confident that the new name is suitable, there is no need to wait for confirmation of the new slug reservation. You may see a warning regarding the 'Text Domain', as we haven't changed the slug on our side yet. That's fine.

Also, please, don't check just this names, there are other places or resources where you may not use any trademarked (or commonly recognized) terms in a manner that is likely to cause confusion. This includes (but is not limited to):

- Your username jmarianski and your display name.
- The plugin contributor's username and display name.
  - jmarianski - jmarianski
- The URLs of this plugin.
- Graphic resources such as this plugin icons and banners.

Are you the owner? If there are any issues with the trademark or project name and this is indeed an official plugin, it is likely that we have not been able to identify the current owner of the plugin as the trademark / project owner.

Keep in mind, if you were hired as a freelance developer or a consultant specifically to create this plugin, then they must own the plugin. Being granted permission is not enough. This is for their legal protection as well as yours. They can add you as a committer to the plugin so you can manage it from your account, but the owner must be them, not a temp-employee.

In order to solve this, you can do one of the following:

- Prove that you legally have the right to the original name/slug.
- Change your user email to an official one or provide us with the user account for an official owner to whom we should transfer the plugin (they can create a new account if they need to)

Note: Should you have this plugin approved with the correct name and then go back to infringement of trademarks, we will close your plugin.

ℹ️ Tips on choosing a suitable name (and avoiding back-and-forth in this process).

🔍️ Avoid Name Similarity

In this context, by "similar" we mean something that goes beyond a letter-by-letter or word-by-word comparison. We also look into similar naming patterns, lookalike names or meanings. You must ensure that the name of your plugin is distinctive and does not cause confusion with others.

How can you check this? Easy!

1. Perform a search engine query for the plugin name (Google, DuckDuckGo, Bing, ChatGPT) and parts of it.
2. If you find other plugins with "similar" names, change your plugin's name to something more distinctive and that won't cause confusion.
3. Go back to step 1 and check again, just in case.

❌ Changing the plugin name by adding an additional letter or a generic word (Advanced, Simple, etc) will probably not solve this problem at all.
✅ Your plugin name has to clearly stand out from other's plugins. You could try adding a coined term, your personal brand or a unique identifier at the beginning of the name. We rely on your creativity!

⚖️ Avoid Trademark / Project Names Confusion

Identify trademarks and project names mentioned in your plugin name "PolyTrans" and the slug "polytrans". The AI has detected ✨ "PolyTrans" as potential trademark(s).

To avoid potential misuse:

- If a trademark or project name is used at the beginning of your plugin name/slug and you are not the owner, it could imply false affiliation.
- If the trademark appears elsewhere in the name/slug but is not part of a clear structure indicating unaffiliation, it may still be problematic.
- Do not use any altered forms of a trademark, such as blend words or portmanteaus.

A safer naming pattern is to place the trademark at the end, following a phrase like "for" or "with".

👀 Examples (assuming a WooCommerce integration and that you are not WooCommerce):

- ❌ "WooCommerce Prices Updater" — implies affiliation.
- ❌ "Prices Updater WooCommerce" — lacks a clear structure indicating no affiliation.
- ❌ "Prices for WooCommerce" — too generic.
- ❌ "Prices for WooCommerce by jmarianski" — the distinguishing term shouldn't go at the end.
- ❌ "AB Prices for WooCommerce" — adding a few letters is not enough.
- ❌ "PricesPress for WooCommerce" — portmanteau using the WordPress trademark.
- ❌ "Prices Updater for WooCommerce" — similarity issue with other plugins.
- ❌ "Easy Prices Updater for WooCommerce" — a generic word does not make it distinguishable.
- ✅ "Priconix Sync for WooCommerce" — original, distinguishable, unique.
- ✅ "jmarianski Prices Updater for WooCommerce" — unique identifier and clear unaffiliation.

### Are you the rightful owner of this plugin?

If your plugin uses a name or URL associated with a specific entity, we need to confirm that you actually represent that entity. In most cases, we rely on the domain in your email address.

This is what we know about this plugin:

- Plugin name: "PolyTrans" in the readme.txt file
- Plugin name: "PolyTrans" in the polytrans.php file
- Slug: polytrans
- Author: treetank
- Author URI: https://treetank.net
- Plugin URI: https://github.com/treetank-net/polytrans
- Contributors: jmarianski

This is what we know about you:

- Username: jmarianski
- Email: <redacted>@gmail.com  <!-- redacted before committing: this repo is mirrored to a public GitHub remote. The gmail.com domain is what the finding is about, so it is kept. -->
  - 🟥 Your email domain "gmail.com" does not seem to be related to any of the URLs, names, trademarks and/or services declared in the plugin.
  - 🟥 A gmail.com account cannot be used as a valid form of identification towards clarifying ownership, even if it contains your or your company's name. Anyone can create any gmail.com account that is not already in use. You could be you, but you could also be anyone else.

☑️ You can demonstrate or clarify ownership in one of the following ways:

- 📩 Update your WordPress.org email address to one under the domain of the entity associated with the plugin. You can change it in your WordPress.org profile, we cannot change it for you. Note: We will continue the review in this email thread. Any new emails will be sent to the new email account.
- 👤 Reply asking us to transfer this submission to the correct WordPress.org account. Please, tell us the username. If the owner does not yet have an account, they can create it using an email that is under the domain of the entity associated to the plugin. ⚠️ Do not resubmit this plugin using the new account! I will transfer it for you. Simply reply to this email with the new username. Note: Once the plugin has been approved, you can ask the owner to add you as a committer in the "Advanced" section of the plugin, so that you can commit code using your account.
- 🛠 Change the plugin's display name and slug to make it clear that the plugin is not officially affiliated with any other entity. Upload an updated version of your plugin via the "Add your plugin" page. Ask us to change the slug.
- Reply to this email clarifying the situation, we know that sometimes you might be using an email account that belongs to the same entity or an entity that owns the other entity. Also, if you already have established plugins in the directory under the same account we can use that as tacit verification.
- Perform a DNS check on the owner's domain. Add a TXT record at the owner's domain root `@` with the following value: `wordpressorg-jmarianski-verification`. Note: This will only be considered valid if that's done in the owner's domain and we can relate your account as being controlled by the owner. If you are a third party please follow other verification methods.

Remember that, if you own other plugins, all the plugins belonging to the same entity should be under the same WordPress.org account.

⚠️ Please do not resubmit this plugin using a different account. Both submissions will be rejected if you do so, and your accounts may be suspended until the situation is resolved. Instead, ask us to change the owner — we can certainly do that.

### Have you checked for common technical issues?

#### 🔴 Nonces and User Permissions Before Processing Requests

ℹ️ Why it matters: Nonces and permissions checks ensure that the request comes from a trusted source, protecting against security threats like CSRF attacks.

🔍 Spot it: Look for functions that interact with `$_GET`, `$_POST`, `$_REQUEST` and perform actions triggered by the user/browser that modify data or perform sensitive actions. For such actions, you should always check for a nonce. Additionally, verify user permissions if the action is restricted to certain roles. When in doubt, play it safe: always check.

🛠 Fix it

1. Create a nonce (`wp_nonce_field()`, `wp_nonce_url()`, `wp_create_nonce()`). If you need to pass the nonce to JavaScript you can make use of `wp_localize_script()`.
2. Pass it with the request.
3. Check the nonce (`check_admin_referer()`, `check_ajax_referer()`, `wp_verify_nonce()`), and permissions if applicable (`current_user_can()`).

```php
function polytr_save_email(){
    if ( !isset( $_POST['polytr_nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['polytr_nonce'] ) ), 'polytr_save_email_action' ) ) {
        wp_send_json_error( 'Invalid nonce.' );
    }

    if ( !current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    if ( isset( $_POST['post_id'] ) && isset( $_POST['price'] ) ) {
        update_post_meta( absint( $_POST['post_id'] ), 'price', floatval( $_POST['price'] ) );
    }
}
add_action( 'wp_ajax_polytr_save_email', 'polytr_save_email' );
```

⚠️ Without nonce and permissions checks, anyone could call this AJAX endpoint and change the price data for any post - risky!

#### 🔴 Proper sanitization of inputs

🔍 Identify unsanitized inputs: Check any input coming from sources such as `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER`, `$_SESSION`.

🛠 Fix it: Always wrap any input with the right sanitization function (`sanitize_text_field()`, `sanitize_email()`, `esc_url_raw()`, `sanitize_key()`, `absint()`, …).

- 👉 Use the most restrictive function that fits the expected content.
- 👉 Sanitize as early as possible — ideally as soon as the data is received.
- 👉 Only trust what you've cleaned yourself.

Examples from your code:

```
includes/Core/TranslationSettings.php:243 ? (string) wp_unslash($_POST[$prompt_template_key]) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin-authored prompt templates must preserve Twig/JSON syntax.
 -----> wp_unslash($_POST[$prompt_template_key])
# ✨ Raw prompt-template values are saved into the settings array without sanitization.

includes/Menu/PostprocessingMenu.php:1190 $evaluator_system_prompt = isset($_POST['evaluator_system_prompt']) ? wp_unslash($_POST['evaluator_system_prompt']) : '';
# ↳ Line 1201: is_string($evaluator_system_prompt) ? $evaluator_system_prompt : ''
# ✨ The raw evaluator system prompt is passed to the workflow refinement service without sanitization.

includes/Menu/PostprocessingMenu.php:1283 $workflow = isset($_POST['workflow']) ? wp_unslash($_POST['workflow']) : [];
# ↳ Line 1304: is_array($workflow) ? $workflow : [],
# ✨ The unsanitized nested workflow payload is passed to the workflow refinement service.

includes/Menu/PostprocessingMenu.php:1338 $system_prompt_template = isset($_POST['description_system_prompt']) ? (string) wp_unslash($_POST['description_system_prompt']) : '';
 -----> wp_unslash($_POST['description_system_prompt'])
# ✨ The raw description system prompt is passed to the description-generation service without sanitization.

... out of a total of 12 incidences.
```

Note: While the `json_decode()` function in PHP is useful for decoding JSON strings, it does not sanitize the input. The `json_decode()` function simply transforms a JSON string into a PHP array or object. Any potentially malicious data or scripts may persist after `json_decode()`.

```
includes/Menu/PostprocessingMenu.php:1495 $job_params = isset($_POST['job_params']) ? json_decode(wp_unslash($_POST['job_params']), true) : [];
```

✔️ You can check this using Plugin Check.

#### 🔴 Proper escaping of outputs

🔍 Identify unescaped outputs: Check any output (`echo`, `print`, `printf`, etc.) outputting a variable or function result.

🛠 Fix it: `esc_url()`, `esc_attr()`, `esc_html()`, `wp_kses()`, `wp_kses_post()`.

- 👉 Use the most restrictive function that fits the context.
- 👉 Escaping should be applied as late as possible, ideally right before output.

✔️ You can check this using Plugin Check.

### Other details

#### 🔴️ Direct integration with a third-party AI provider detected.

Your plugin appears to call a third-party AI provider directly (via its HTTP API or a PHP SDK declared in composer.json).

Since WordPress 7.0, the core AI Client ships in WordPress itself. Plugins are encouraged to use it instead of integrating with providers directly. Benefits include:

- The site owner chooses and configures the provider once, at the site level.
- Credentials are managed by core, not by each plugin.
- Your plugin works out-of-the-box with whichever provider the user has set up.

Please consider migrating your AI features to the WordPress AI Client.

```
includes/Providers/Claude/ClaudeSettingsProvider.php:150 $client = new HttpClient('https://api.anthropic.com/v1', 10);
includes/Providers/OpenAI/OpenAIClient.php:33 public function __construct($api_key, $base_url = 'https://api.openai.com/v1', $default_timeout = null)
includes/Providers/Gemini/GeminiSettingsProvider.php:144 'models_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
includes/Providers/Gemini/GeminiSettingsProvider.php:208 $client = new HttpClient('https://generativelanguage.googleapis.com/v1beta', 10);
includes/Providers/Gemini/GeminiChatClientAdapter.php:25 public function __construct($api_key, $base_url = 'https://generativelanguage.googleapis.com/v1beta')
includes/Providers/OpenAI/OpenAISettingsProvider.php:1115 'base_url' => 'https://api.openai.com/v1',
includes/Providers/Claude/ClaudeSettingsProvider.php:335 $client = new HttpClient('https://api.anthropic.com/v1', 10);
includes/PostProcessing/Steps/AiAssistantStep.php:411 $base_url = $polytrans_settings['openai_base_url'] ?? 'https://api.openai.com/v1';

... out of a total of 38 incidences.
```

#### 🔴️ Out of Date Libraries

At least one of the 3rd party libraries you're using is out of date. Please upgrade to the latest stable version for better support and security. We do not recommend you use beta releases.

```
twig/twig v3.22.1 => v3.28.0
```

#### 🟡 Translation files included

We detected possible translation files (.po, .mo and/or .php) included in your plugin.

Thank you for making your plugin available in multiple languages. However, plugins hosted on WordPress.org can manage translations through translate.wordpress.org, which:

- Automatically generates translation files for every locale.
- Allows the community to contribute translations in any supported language.
- Delivers translations to users through the standard WordPress translation update system.

By using this, there is no need to include translation files in your plugin package. Instead, ensure that your plugin is properly internationalized (i18n).

```
polytrans/languages/polytrans-pl_PL.po
```

#### 🔴️ Calling core loading files directly

Calling core files like `wp-config.php`, `wp-blog-header.php`, `wp-load.php` directly via an include is not permitted.

These calls are prone to failure as not all WordPress installs have the exact same file structure. In addition it opens your plugin to security issues, as WordPress can be easily tricked into running code in an unauthenticated manner.

Your code should always exist in functions and be called by action hooks. There are some exceptions to the rule in certain situations and for certain core files. In that case, we expect you to use `require_once` to load them and to use a function from that file immediately after loading it.

```
includes/Core/LogsManager.php:783 require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
# ✨ Loads the WordPress WP_List_Table core file even though the admin page uses custom pagination and does not use the class afterward.
```

#### 🔴️ Determine files and directories locations correctly

We detected that the way your plugin references some files, directories and/or URLs may not work with all WordPress setups. This happens because there are hardcoded references or you are using the WordPress internal constants.

https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/

```
includes/Scheduler/TranslationHandler.php:331 $translation_receiver_endpoint = site_url('/wp-json/polytrans/v1/translation/receive-post');
# ✨ Hardcodes the REST base path; use rest_url('polytrans/v1/translation/receive-post') to support custom REST prefixes and URL configurations.
```

#### 🔴️ Creating / login users

Creating and/or logging in users could pose a serious security risk on sites using this plugin.

In most cases, you won't be allowed to create users on other people's sites, and you really don't need to. If you do need authenticated requests to WordPress APIs, you can ask the user to add an application password instead.

Also note that WordPress has its own methods to create/login users that can be extended by other plugins, such as security plugins that block multiple login attempts. Creating your own method can bypass these checks, leaving sites vulnerable to such attacks.

```
includes/PostProcessing/WorkflowOutputProcessor.php:73 wp_set_current_user($attribution_user_id);
# ✨ An edit_posts-level user can save a workflow with an arbitrary existing attribution user, allowing workflow changes to execute under that user's privileges.
```

#### 🔴️ Check permission_callback in REST API Route

When using `register_rest_route()` or `wp_register_ability()` to define custom REST API endpoints, it is crucial to include a proper `permission_callback`.

✅ When a permission_callback is NOT Required: valid public endpoints — use `__return_true` to indicate that the endpoint is intentionally public.
🔒 When a permission_callback IS Required: endpoints involving sensitive data or actions.

```
includes/Receiver/TranslationReceiverExtension.php:166 register_rest_route('polytrans/v1', '/translation/receive-post', ['methods' => 'POST', 'callback' => [$this, 'handle_receive_post'], 'permission_callback' => [$this, 'permission_callback']]);
# ↳ Detected: permission_callback
# ✨ The callback deliberately permits unauthenticated requests when the secret method is set to "none", allowing arbitrary callers to create translated posts and trigger workflows.

includes/Core/TranslationExtension.php:72 register_rest_route('polytrans/v1', '/translation/translate', ['methods' => 'POST', 'callback' => [$this, 'handle_translate'], 'permission_callback' => [$this, 'permission_callback']]);
# ↳ Detected: permission_callback
# ✨ The callback deliberately permits unauthenticated requests when the secret method is set to "none", although this endpoint initiates translation processing and billable provider actions.
```

#### 🔴️ Other possible issues

The AI detected certain cases not classified to specific sections of this report that can be related to security, compatibility, guidelines or other potential issues.

```
includes/PostProcessing/WorkflowManager.php:859 set_transient($lock_key, ['execution_id' => $execution_id, 'started_at' => $started_at, 'workflow_id' => $workflow_id, 'post_id' => $translated_post_id], 5 * MINUTE_IN_SECONDS);
# ✨ The manual workflow endpoint checks only edit_posts and does not verify edit_post permission for the supplied translated post ID.

includes/Core/PostAutocomplete.php:255 \set_transient($cache_key, $results, 5 * MINUTE_IN_SECONDS);
# ✨ The shared transient cache can expose private-post content cached for a more privileged user to another user with only edit_posts capability.
```

### 👉 Your next steps

This is your checklist:

1. Have you read the guidelines and this plugin complies with them?
2. Is the name of this plugin descriptive and distinctive, and does it respect the trademarks and project names of others?
3. Are you the rightful owner of this plugin?
4. Have you checked for common technical issues?
5. Other details

If there is something that needs to be fixed, please:

1. Take your time and fix it. Make sure that everything was addressed and the plugin works.
2. Update your plugin files at the "Add your plugin" page, while being logged in with your account "jmarianski".
3. Reply to this email.
4. Please keep your reply short, direct and clear. Avoid overly verbose and long AI responses. Do not list the changes made, we don't need that, we will review the entire plugin again, we won't compare the changes. However, please share any important context or clarifications that may help us during the review.

If after checking the list and doing the changes you feel that everything is right or need further clarification, please reply to this email and a volunteer will help you.

If you believe there is a requirement you cannot accomplish and choose not to make changes, your plugin submission will be rejected after three months.

## Disclaimers

If, at any time during the review process, you wish to change your permalink (aka the plugin slug) "polytrans", you must explicitly and clearly tell us what you would like it to be. Just changing it in your code and in the display name is not sufficient. Remember, permalinks cannot be altered after approval.

This email was partially auto-generated, so please be aware that some information might not be entirely accurate. No personal data was shared with the AI during this process.

Review ID: AUTOPREREVIEW ❗TRM-OWN polytrans/jmarianski/24Aug26/T1 24Aug26/4.2 (P0TDX357593HGN)
