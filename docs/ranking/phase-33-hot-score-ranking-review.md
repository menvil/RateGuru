# Phase 33 Hot Score & Ranking Review

- Upvotes increase score.
- Age decreases score.
- Comments increase score with a lower weight than upvotes.
- Downvotes reduce net vote contribution without making the score negative.
- Scores are stored in `posts.hot_score`.
- Post votes recalculate `hot_score` synchronously after successful votes.
- Comments recalculate `hot_score` synchronously after successful creation.
- `posts:recalculate-hot-scores` recalculates all posts in chunks.
- No queue, Redis, scheduler, or UI dependency was introduced.
- Formula constants live in `App\Support\Ranking\HotScoreCalculator`.
