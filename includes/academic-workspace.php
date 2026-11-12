<header class="aw-header">
 <div><span class="aw-eyebrow">REGISTRAR / ACADEMIC RECORDS</span><h1>Academic History</h1><p>Review student grades, verify term records, and prepare academic documents.</p></div>
</header>
<p style="text-align:right;margin:0 0 12px"><a href="academic-validation.php">Academic validation</a></p>
<nav class="aw-tabs" aria-label="Academic workspace">
 <button type="button" class="is-active" data-workspace-tab="students" aria-pressed="true">Student history</button>
 <button type="button" data-workspace-tab="review" aria-pressed="false">Term review</button>
</nav>
<section id="awStudents" class="aw-card">
 <form method="get" class="aw-filters">
  <label>Academic period<select name="term" onchange="this.form.submit()">
  <?php foreach ($years as $year): foreach ($SEMESTERS as $semester): $value = $year.' '.$semester; ?>
   <option value="<?= htmlspecialchars($value) ?>" <?= $value === $termCanonical ? 'selected' : '' ?>><?= htmlspecialchars(termLabel($year, $semester)) ?></option>
  <?php endforeach; endforeach; ?>
  </select></label>
  <label>Program<select id="awProgram"><option value="">All programs</option><?php foreach (array_unique(array_column($rows, 'program')) as $course): ?><option><?= htmlspecialchars($course) ?></option><?php endforeach; ?></select></label>
  <span class="aw-badge"><?= count($rows) ?> students</span><span class="aw-badge green"><?= $termComplete ?> complete records</span><span class="aw-badge amber"><?= $termMissing ?> awaiting grades</span>
 </form>
 <div class="aw-table-head"><div><h2>Student academic records</h2><p>Select a student to explore subjects and previous semesters.</p></div><label class="aw-search"><span class="ah-sr-only">Search students</span><input id="awSearch" type="search" placeholder="Search student name or ID…"></label></div>
 <div class="aw-table-scroll"><table class="aw-table"><thead><tr><th>Student</th><th>Program / year</th><th>Terms recorded</th><th>Selected term GWA</th><th>Record status</th><th>Action</th></tr></thead><tbody>
 <?php foreach ($rows as $student): $sid = (int)$student['id']; $accepted = !empty($termRecords[$sid]['accepted_at']); ?>
 <tr data-aw-student data-program="<?= htmlspecialchars($student['program']) ?>" data-search="<?= htmlspecialchars(strtolower($student['name'].' '.$student['number'])) ?>">
  <td><div class="aw-person"><span class="aw-avatar"><?= htmlspecialchars(ah_initials($student['name'])) ?></span><div><strong><?= htmlspecialchars($student['name']) ?></strong><small><?= htmlspecialchars($student['number'] ?: 'No student number') ?></small></div></div></td>
  <td><?= htmlspecialchars(ahProgramShort($student['program'])) ?><small><?= htmlspecialchars($student['level'] ? 'Year '.$student['level'] : 'Year not recorded') ?></small></td>
  <td><?= count($careerTerms[$sid] ?? []) ?></td><td><strong><?= $student['gwa'] === null ? '—' : number_format($student['gwa'],2) ?></strong><?php if (!$accepted && $student['gwa'] !== null): ?><small>Provisional</small><?php endif; ?></td>
  <td><span class="aw-badge <?= $accepted ? 'green' : ($student['state'] === 'complete' ? '' : 'amber') ?>"><?= $accepted ? 'Accepted' : ($student['state'] === 'complete' ? 'For review' : ($student['state'] === 'none' ? 'Awaiting submission' : 'Needs correction')) ?></span></td>
  <td><button type="button" class="aw-view ah-view" data-view="<?= $sid ?>">View history <i class="fa-solid fa-arrow-right"></i></button></td>
 </tr><?php endforeach; ?>
 </tbody></table></div><p class="aw-empty" id="awEmpty" hidden>No students match your search.</p><div class="aw-table-foot" id="awCount"><?= count($rows) ?> student records</div>
</section>
<section id="awReview" hidden>
