<?php
// common.php -- shared functions included by all pages

function common_css() { return '
<style>
@import url(\'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Playfair+Display:wght@700&display=swap\');
:root {
  --bg:      #ffffff;
  --surface: #ffffff;
  --border:  #000000;
  --text:    #000000;
  --dim:     #000000;
  --header:  #eeeeee;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: \'DM Mono\', monospace;
  padding: 2.5rem 2rem;
  max-width: 980px;
  margin: 0 auto;
}
h1 { font-family: \'Playfair Display\', serif; font-size: 2rem; margin-bottom: 0.2rem; }
.subtitle { color: var(--text); font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; margin-bottom: 2rem; }
button, .btn {
  background: #eeeeee;
  border: 1px solid #000000;
  color: #000000;
  font-family: \'DM Mono\', monospace;
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  padding: 0.45rem 1rem;
  cursor: pointer;
  text-transform: uppercase;
  text-decoration: none;
  display: inline-block;
}
button:hover, .btn:hover { background: #cccccc; }
.btn-sm {
  background: transparent;
  border: 1px solid #000000;
  color: #000000;
  font-family: \'DM Mono\', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  padding: 0.25rem 0.6rem;
  cursor: pointer;
  text-transform: uppercase;
  text-decoration: none;
  display: inline-block;
}
.btn-sm:hover { background: #eeeeee; }
</style>
'; }
?>
