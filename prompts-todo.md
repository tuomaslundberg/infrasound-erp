Great. Now our first real task is to port the existing customer/quote/gig registry into a more machine-friendly form, and subsequently automate as much of the quote calculation and customer info workflow as possible. When this system matures, we're going to implement a separate agent service that takes care of simple custom agent orchestration (e.g., using Go and Google ADK or similar), for tasks that require LLM calls, but generally speaking, there are (and should be) very few of them. The vast majority of these steps are viable via very plain scripting logic.

Since the business is operating as we speak, I need a quick'n'dirty MVP version of this workflow automation to be able to process customer requests. We could begin by importing everything into CSV, implement a non-robust placeholder script that could process the majority of data I/O, and only after that is working, start building the database. This would act as an intermediate step anyway.

You can find the old aggregate documents and a template folder in the project root folder. Here's a brief description of these:



Keep in mind that these files and other naming conventions mix Finnish and English language use. We should probably aim to convert things into English where applicable, though.