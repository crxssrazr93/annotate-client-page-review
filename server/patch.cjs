'use strict';
// Patches the vendored annotate reference server (annotate-server.cjs) at build time:
//  1. Read reviews from an external Map so they can be added without a restart.
//  2. Add a per-review `shared` board: when review.shared is true, every reviewer is a
//     peer. Everyone can VIEW all comments and also edit, resolve and delete any comment
//     or reply. In a non-shared review the original ownership checks (author only, or a
//     review author) still apply.
const fs = require('node:fs');
const file = process.argv[2] || 'annotate-server.cjs';
let s = fs.readFileSync(file, 'utf8');

function replaceOnce(find, repl) {
  const i = s.indexOf(find);
  if (i === -1) throw new Error('patch target not found: ' + find.slice(0, 60));
  if (s.indexOf(find, i + 1) !== -1) throw new Error('patch target not unique: ' + find.slice(0, 60));
  s = s.slice(0, i) + repl + s.slice(i + find.length);
}

// 1. External reviews Map
replaceOnce(
  'const reviews = new Map((options.reviews || []).map(r => [r.id, r]));',
  'const reviews = options.reviewsMap || new Map((options.reviews || []).map(r => [r.id, r]));'
);

// 2a. Add canView next to canSee (ownership) without changing canSee.
replaceOnce(
  '  function canSee(review, reviewer, record) {\n    return record.authorId === reviewer.id || isAuthor(review, reviewer);\n  }',
  '  function canSee(review, reviewer, record) {\n    return record.authorId === reviewer.id || isAuthor(review, reviewer);\n  }\n  // A shared review lets every reviewer VIEW all comments; ownership (canSee) still gates edits/deletes.\n  function canView(review, reviewer, record) {\n    return review.shared === true || canSee(review, reviewer, record);\n  }'
);

// 2b. Widen the VIEW sites to canView (listing, activity, reply-to, reply visibility precondition).
replaceOnce(
  '(includeDeleted || !c.deletedAt) && canSee(review, reviewer, c))',
  '(includeDeleted || !c.deletedAt) && canView(review, reviewer, c))'
);
replaceOnce(
  "if (!data.comments[id] || !canSee(review, reviewer, data.comments[id])) throw new HttpError(404, 'comment_not_found');",
  "if (!data.comments[id] || !canView(review, reviewer, data.comments[id])) throw new HttpError(404, 'comment_not_found');"
);
replaceOnce(
  "if (!stored || !canSee(review, reviewer, stored)) throw new HttpError(404, 'comment_not_found');",
  "if (!stored || !canView(review, reviewer, stored)) throw new HttpError(404, 'comment_not_found');"
);
replaceOnce(
  'const reply = stored && !stored.deletedAt && canSee(review, reviewer, stored) && stored.replies.find(r => r.id === replyId);',
  'const reply = stored && !stored.deletedAt && canView(review, reviewer, stored) && stored.replies.find(r => r.id === replyId);'
);

// 2c. Widen the two comment ownership guards (edit/resolve + delete-comment) to canView,
//     so a shared review lets everyone change any comment. Both lines are identical.
const ownGuard = "if (!canSee(review, reviewer, stored)) throw new HttpError(403, 'not_owner');";
const ownCount = s.split(ownGuard).length - 1;
if (ownCount !== 2) throw new Error('expected 2 comment ownership guards, found ' + ownCount);
s = s.split(ownGuard).join("if (!canView(review, reviewer, stored)) throw new HttpError(403, 'not_owner');");

// 2d. Reply delete: author or review author, plus anyone in a shared review.
replaceOnce(
  "if (reply.authorId !== reviewer.id && !isAuthor(review, reviewer)) throw new HttpError(403, 'not_owner');",
  "if (reply.authorId !== reviewer.id && !isAuthor(review, reviewer) && review.shared !== true) throw new HttpError(403, 'not_owner');"
);

// 3. Self-serve codes: when review.selfServe is true, an unknown invite code becomes a
//    stable, self-created identity derived from (reviewId + code). No settings entry needed;
//    reopening with the same code on any device is the same user.
replaceOnce(
  "      const invite = (review.invites || []).find(i => i.token === body.invite);\n      if (!invite) throw new HttpError(403, 'invite_invalid');\n      if (invite.revoked) throw new HttpError(403, 'invite_revoked');\n      if (invite.expiresAt && Date.parse(invite.expiresAt) <= now()) throw new HttpError(403, 'invite_expired');\n      reviewer = { id: invite.reviewer.id, name: invite.reviewer.name };\n      reviewer.role = isAuthor(review, reviewer) ? 'author' : 'reviewer';",
  "      const invite = (review.invites || []).find(i => i.token === body.invite);\n      if (!invite) {\n        if (review.selfServe && /^[A-Za-z0-9_-]{1,64}$/.test(body.invite)) {\n          const sid = 'rvs_' + crypto.createHash('sha256').update(review.id + ':' + body.invite).digest('base64url').slice(0, 20);\n          const nm = (typeof body.name === 'string' && body.name.trim()) ? body.name.trim().slice(0, 80) : body.invite;\n          reviewer = { id: sid, name: nm };\n          reviewer.role = isAuthor(review, reviewer) ? 'author' : 'reviewer';\n        } else {\n          throw new HttpError(403, 'invite_invalid');\n        }\n      } else {\n        if (invite.revoked) throw new HttpError(403, 'invite_revoked');\n        if (invite.expiresAt && Date.parse(invite.expiresAt) <= now()) throw new HttpError(403, 'invite_expired');\n        reviewer = { id: invite.reviewer.id, name: invite.reviewer.name };\n        reviewer.role = isAuthor(review, reviewer) ? 'author' : 'reviewer';\n      }"
);

const canSeeOwn = (s.match(/if \(!canSee\(review, reviewer, stored\)\) throw new HttpError\(403, 'not_owner'\);/g) || []).length;
if (canSeeOwn !== 0) throw new Error('expected 0 canSee ownership guards after widening, found ' + canSeeOwn);
const canViewOwn = (s.match(/if \(!canView\(review, reviewer, stored\)\) throw new HttpError\(403, 'not_owner'\);/g) || []).length;
if (canViewOwn !== 2) throw new Error('expected 2 canView ownership guards, found ' + canViewOwn);
if (!s.includes('function canView(')) throw new Error('canView not inserted');

fs.writeFileSync(file, s);
console.log('patched annotate-server.cjs: reviewsMap + shared-view (canView)');
