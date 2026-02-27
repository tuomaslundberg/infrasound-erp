### THIS FILE CONTAINS DRAFTS FOR FUTURE LLM PROMPTS CONCERNING THE DEVELOPMENT OF THIS PROJECT, AND SHOULD THEREFORE BE CONSIDERED OFF-LIMITS BY ANY LLM AGENTS WORKING WITH THIS PROJECT, IN ORDER TO PREVENT CONTEXT BLOAT AND WORKFLOW CONFUSION ###

#### TODO ####



#### PROMPTS BACKLOG ####



#### PROMPT BACKUPS ####

Great. Now our first real task is to port the existing customer/quote/gig registry into a more machine-friendly form, and subsequently automate as much of the quote calculation and customer info workflow as possible. When this system matures, we're going to implement a separate agent service that takes care of simple custom agent orchestration (e.g., using Go and Google ADK or similar), for tasks that require LLM calls, but generally speaking, there are (and should be) very few of them. The vast majority of these steps are viable via very plain scripting logic.

Since the business is operating as we speak, I need a quick'n'dirty MVP version of this workflow automation to be able to process customer requests. One direction to go for would be to import everything into CSV, implement a (possibly non-robust) placeholder script that could process the majority of data I/O, and only after that is working, start building the database. On the other hand, we could lay down the basic database schema, which would probably already enable asynchronous, small feature PRs. In this case, we could directly move to creating a todo-list after the schema has been designed. In any case, I believe I would benefit greatly if I'd be able to also run the actual processing scripts locally, i.e., `php process_inquiry.php`, or something like that, without spinning containers up and operating from the web browser interface. That would enable me to perform daily operations quickly in the intermediary state where no project deployment exists yet.

I have created a separate file called `MIGRATION_DOC.md`, where I'm going to incrementally document our old file hierarchy and business processes. The only flow currently there describes the most important one: answering to new business inquiries.

You can find the old aggregate documents and a template folder in the project root folder, under `./old-files`. A brief description of these can be found in `MIGRATION_DOC.md`.

Keep in mind that these files and other naming conventions mix Finnish and English language use. We should probably aim to convert things into English where applicable, though. Another essential thing to consider is that we are dealing with very little data here: in the next 5 years, we are going to have a couple of thousand new rows in the gig table *at most*, for example. So there's really no need to optimise for performance. Another constraint to consider would be that we need to keep gig information human-readable at all times, at least in some form, since external musicians need access to playlists, venue locations, etc.

Finally, a very basic and brief napkin sketch I did in my head regarding the database schema. The basic entities and relations that first come to my mind are listed here.

**Entities**

- `customer`
- `contact`
- `gig`
- `gig_personnel`
- `venue`
- `outgoing_invoice`
- `incoming_invoice` (i.e., external musicians invoicing us)
- `setlist`
- `song`
- `platform`/`sales_channel` (TBD, might be useful for quote composition logic)

**Relations**

- `customer` should have one main/default `contact`, but `contact` CAN be associated with multiple `customer`s (think promoters selling gigs to multiple clubs).
- `customer` can be associated with multiple `gig`s, but not the other way around.
- Multiple `gig_personnel` are responsible for multiple `gig`s (these are musicians and/or sound technicians). I'm not yet sure how these roles should be handled, since the same person can act different roles on different gigs.
- `venue` can have multiple `gig`s; not the other way around.
- An `outgoing_invoice` concerns a single `gig`; these are 1:1.
- Multiple `incoming_invoice`s can result from a single `gig`.
- A `setlist` has 1:1 correspondence with a `gig`.
- Multiple `incoming_invoice`s can pertain to a single `gig_personnel`, e.g. a musician.
- A `setlist` is composed of multiple `song`s, and a `song` can appear on multiple `setlist`s.

These lists are incomplete and most certainly need a review; this is just to provide a starting point for the database from a product owner perspective.

Based on this, let's start creating a real plan to begin implementing the system. Make sure you familiarise yourself with the necessary context. Again, let's keep AGENTS.md and CLAUDE.md up to date if basic project directory structure is to change, or if essential constraints or guidelines are missing.
