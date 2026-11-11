<div class="aw-modal ap-overlay" id="printModal" hidden>
 <div class="aw-dialog ap-dialog" role="dialog" aria-modal="true" aria-labelledby="printModalTitle">
  <header class="aw-dialog-head"><div class="ap-title"><span class="ap-icon"><i class="fa-solid fa-print" aria-hidden="true"></i></span><div><h2 id="printModalTitle" tabindex="-1">Print academic record</h2><p>Choose the document and confirm the exact academic period.</p></div></div><button class="aw-close" type="button" data-ap-close aria-label="Close print options">×</button></header>
  <div class="aw-identity" id="apIdentity"></div>
  <div class="ap-body"><div class="ap-settings">
   <fieldset><legend>Document type</legend>
    <label class="ap-choice"><input type="radio" name="apDocument" value="term" checked><span><strong>Semester grade record</strong><small>Subjects, final grades, and term GWA</small></span></label>
    <label class="ap-choice"><input type="radio" name="apDocument" value="all"><span><strong>Complete academic history</strong><small>All recorded terms and cumulative GWA</small></span></label>
    <label class="ap-choice ap-disabled"><input type="radio" name="apDocument" disabled><span><strong>Semester GWA certification <em>Planned</em></strong><small>Approved certificate design · saved for a future update</small></span></label>
   </fieldset>
   <h3>Academic period</h3><div class="ap-period"><label>School year<select id="apYear"></select></label><label>Semester<select id="apSemester"></select></label></div>
   <p id="apScope" class="ap-scope">Only this school year and semester will be included.</p>
   <h3>Output</h3><div class="ap-output"><span><i class="fa-solid fa-print" aria-hidden="true"></i> Print / Save as PDF</span><span class="ap-disabled">Word (.docx) <em>Planned</em></span></div>
  </div><aside class="ap-summary"><h3>Print summary</h3><dl id="apSummary"></dl><p id="apWarning" class="ap-warning"></p></aside></div>
  <footer class="aw-modal-footer"><small>Printing does not change academic records.</small><div><button type="button" class="btn btn-light" data-ap-close>Cancel</button><button type="button" class="btn btn-primary" id="apPrint">Open print preview <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></div></footer>
 </div>
</div>
