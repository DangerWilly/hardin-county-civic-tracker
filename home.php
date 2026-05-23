<?php
/**
 * src/views/home.php
 *
 * Receives:
 *   $county — array row from jurisdictions
 *   $city   — array row from jurisdictions
 *
 * The homepage's job is to make the AI assistant feel inevitable. Big ask
 * box, dignified typography, three "what's underneath" cards. The form
 * is currently disabled — wiring it to xAI is the next coding step.
 */
?>
<main class="container">

    <section class="lede">
        <p class="lede__kicker">For neighbors of <?= e($county['name']) ?> and <?= e($city['name']) ?></p>

        <h1 class="lede__hed">
            Ask anything about your local government.
        </h1>

        <p class="lede__dek">
            A 40-page agenda PDF, distilled. Bills your state senator voted on, in plain English. Where to vote, when, and on what.
        </p>

        <form class="ask" action="/ask" method="post" data-stub="true">
            <label class="ask__label" for="ask-input">Your question</label>
            <textarea
                id="ask-input"
                name="q"
                rows="3"
                placeholder="What is the city council voting on this week?"
                autocomplete="off"
                disabled></textarea>
            <div class="ask__row">
                <button type="submit" class="ask__btn" disabled>Ask &rarr;</button>
                <p class="ask__hint">Wiring this to xAI is the next coding step. Free. Anonymous. Answers will cite their sources.</p>
            </div>
        </form>
    </section>

    <section class="grid" aria-label="What you'll find here">

        <article class="card">
            <p class="card__kicker">This Week</p>
            <h2 class="card__hed">Upcoming meetings</h2>
            <p class="card__dek">City council, county commissioners, school board &mdash; with summaries of every agenda item.</p>
            <a href="/meetings" class="card__link">See the calendar &rarr;</a>
        </article>

        <article class="card">
            <p class="card__kicker">Statehouse</p>
            <h2 class="card__hed">Bills affecting Hardin County</h2>
            <p class="card__dek">What your state senator and representative voted on, summarized in plain language.</p>
            <a href="/bills" class="card__link">Read the digest &rarr;</a>
        </article>

        <article class="card">
            <p class="card__kicker">Civic Toolkit</p>
            <h2 class="card__hed">How to vote, register, and contact reps</h2>
            <p class="card__dek">Everything you need, no bureaucratese.</p>
            <a href="/voter-info" class="card__link">Open the toolkit &rarr;</a>
        </article>

    </section>

</main>
