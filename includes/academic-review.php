<div class="ar-toolbar aw-card">
 <form method="get"><input type="hidden" name="view" value="review"><label>Academic period<select name="term" onchange="this.form.submit()">
 <?php foreach ($years as $year): foreach ($SEMESTERS as $semester): $value=$year.' '.$semester; ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $termCanonical ? 'selected' : '' ?>><?= htmlspecialchars(termLabel($year,$semester)) ?></option><?php endforeach; endforeach; ?>
 </select></label></form>
 <label>Program<select id="arProgram"><option value="">All programs</option><?php foreach(array_unique(array_column($sectionGates,'program')) as $course): ?><option><?= htmlspecialchars($course) ?></option><?php endforeach; ?></select></label>
 <label>Review status<select id="arStatus"><option value="">All statuses</option><option value="ready">Ready for review</option><option value="waiting">Needs attention</option><option value="accepted">Accepted</option></select></label>
 <label>Search<input id="arSearch" type="search" placeholder="Search program or section…"></label>
</div>
<div class="ar-summary" id="arSummary" aria-live="polite"></div>
<section class="aw-card ar-submissions"><div class="aw-table-head"><div><h2>Term submissions</h2><p>Acceptance verifies the record, not whether every student passed.</p></div></div>
 <div class="aw-table-scroll"><table class="aw-table"><thead><tr><th>Program / section</th><th>Records received</th><th>Issues</th><th>Review status</th><th>Action</th></tr></thead><tbody id="arRows"></tbody></table></div>
 <p id="arEmpty" class="aw-empty" hidden>No section submissions match these filters. Students without an assigned section remain available in Student history.</p>
</section>
<section class="aw-card ar-detail" id="arDetail" hidden aria-labelledby="arDetailTitle"></section>
<p class="ar-footnote">Review is grouped by the current program and section. Academic period: <?= htmlspecialchars(termLabel($sy,$sem)) ?>.</p>
<script type="application/json" id="arData"><?= json_encode(array_values($sectionGates), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
