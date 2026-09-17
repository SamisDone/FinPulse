<?php
defined('FINPULSE') || exit;

redirect_if_logged_in();

page_open('FinPulse', [
    'description' => 'A quiet ledger for income, spending, budgets and savings goals. No bank logins, no trackers, and your data is yours to export.',
]);
?>
<div class="site">
  <header class="site-header">
    <div class="wrap">
      <a class="brand" href="<?= e(url()) ?>"><?= brand_mark() ?><span>FinPulse</span></a>
      <nav class="site-nav" aria-label="Primary">
        <a class="text-link hide-sm" href="#features">Features</a>
        <a class="text-link hide-sm" href="#privacy">Privacy</a>
        <a class="text-link" href="<?= e(url('login')) ?>">Sign in</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('register')) ?>">Create account</a>
      </nav>
    </div>
  </header>

  <main id="main">
    <section class="hero">
      <div class="wrap hero-grid">
        <div>
          <h1>Know where your money <em>went</em>, and where it's going.</h1>
          <p class="lede">FinPulse is a quiet ledger for your income, spending, budgets and savings goals. Log what happens, see whether you're on pace, and never hand over a bank login.</p>
          <div class="hero-cta">
            <a class="btn btn-primary btn-lg" href="<?= e(url('register')) ?>">Start your ledger <?= icon('arrow-right') ?></a>
            <a class="link" href="<?= e(url('login')) ?>">I already have an account</a>
          </div>
          <p class="hero-note"><?= icon('lock', 'icon icon-sm') ?>Free to use. No bank logins, no ads, no trackers.</p>
        </div>

        <div class="hero-visual" aria-hidden="true">
          <div class="statement">
            <div class="statement-head">
              <span class="eyebrow">Left to spend in <?= e(date('F')) ?></span>
              <span class="tag">Sample</span>
            </div>
            <p class="figure"><span class="currency">$</span>1,284.60</p>
            <div class="statement-rows">
              <div class="ledger-row"><span class="ledger-icon in">SA</span><div class="ledger-main"><p class="ledger-title">Salary</p><p class="ledger-meta">Primary job · <?= e(date('M')) ?> 1</p></div><span class="ledger-amount pos">+$4,200.00</span></div>
              <div class="ledger-row"><span class="ledger-icon out">RE</span><div class="ledger-main"><p class="ledger-title">Apartment rent</p><p class="ledger-meta">Rent · Bank transfer</p></div><span class="ledger-amount">−$1,450.00</span></div>
              <div class="ledger-row"><span class="ledger-icon out">GR</span><div class="ledger-main"><p class="ledger-title">Weekly shop</p><p class="ledger-meta">Groceries · Debit card</p></div><span class="ledger-amount">−$86.42</span></div>
              <div class="ledger-row"><span class="ledger-icon out">TR</span><div class="ledger-main"><p class="ledger-title">Monthly transit pass</p><p class="ledger-meta">Transport · Mobile wallet</p></div><span class="ledger-amount">−$89.00</span></div>
            </div>
            <div class="statement-budget">
              <div class="spread small"><span>Groceries budget</span><span class="num muted">$386 of $520</span></div>
              <div class="progress progress-sm"><div class="progress-bar" style="--value:74%"></div><span class="progress-marker" style="--at:57%"></span></div>
            </div>
            <div class="statement-tab">
              <span>Emergency fund</span>
              <strong>68%</strong>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="band" id="features">
      <div class="wrap band-head">
        <h2>Everything a monthly money check-in needs.</h2>
        <div class="feature-list">
          <article class="feature">
            <span class="glyph"><?= icon('expense') ?></span>
            <div>
              <h3>Log spending in seconds</h3>
              <p>Amount, date, category, done. Type a new category and it's created on the spot. Entries are grouped by day with running totals, and you can edit anything you got wrong.</p>
            </div>
          </article>
          <article class="feature">
            <span class="glyph"><?= icon('budget') ?></span>
            <div>
              <h3>Budgets that show your pace</h3>
              <p>Each budget shows what you've spent, what's left per day, and a marker for where you should be by today. You'll know you're running hot on the 12th, not the 30th.</p>
            </div>
          </article>
          <article class="feature">
            <span class="glyph"><?= icon('target') ?></span>
            <div>
              <h3>Goals with a monthly number</h3>
              <p>Set a target and a date and FinPulse tells you how much to put aside each month. Add money as you save and watch the goal close out.</p>
            </div>
          </article>
          <article class="feature">
            <span class="glyph"><?= icon('reports') ?></span>
            <div>
              <h3>Reports you can take with you</h3>
              <p>Compare income and spending month over month, see which categories grew, and export any date range to CSV or PDF.</p>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section class="band" id="privacy">
      <div class="wrap band-head">
        <h2>Private by design.</h2>
        <div class="facts">
          <div class="fact"><strong>No bank connections</strong><p>You enter what matters. No credentials to leak, no aggregator reading your statements.</p></div>
          <div class="fact"><strong>Your data, exportable</strong><p>Download every entry as a spreadsheet, or any report as a PDF, whenever you like.</p></div>
          <div class="fact"><strong>No trackers</strong><p>No analytics, no ads, no third-party cookies. Even the fonts and charts load from FinPulse itself.</p></div>
          <div class="fact"><strong>Locked down</strong><p>Hashed passwords, sign-in throttling, and a strict security policy. Changing your password signs out every other device.</p></div>
        </div>
      </div>
    </section>

    <section class="closing">
      <div class="wrap">
        <div class="closing-card">
          <h2>Your first month takes about ten minutes to set up.</h2>
          <a class="btn btn-lg" href="<?= e(url('register')) ?>">Create your account <?= icon('arrow-right') ?></a>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <a class="brand" href="<?= e(url()) ?>"><?= brand_mark() ?><span>FinPulse</span></a>
      <p>&copy; <?= date('Y') ?> Samonwita Sarker. All rights reserved.</p>
    </div>
  </footer>
</div>
<?php page_close(); ?>
