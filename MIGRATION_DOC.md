# Documentation for data migration

This documentation tries to describe only how things used to be, and furthermore, is a work in progress.

# File hierarchy

- `future-gigs`: The template folder is copied here to represent a single gig, and all info particular to that gig lives here.
	- `quotes`: this folder contains forks of the `gig-info-yymmdd.txt` template file copied and edited to contain a single, unresolved gig inquiry. When a date has been confirmed, the file is moved to its respective `future-gigs/` directory.
- `ignore`: Everything in here can be ignored *for now*. Here is a brief documentation of its contents nevertheless.
	- `documents`: Contains customer-facing, polished PDF documents and the templates thereof, e.g., contract terms and repertoire. *Large context load because of PDF format; not needed yet.*
	- `marketing`: Mainly text/other small files containing marketing texts that are deployed across different Finnish party band aggregator services. *Not needed yet.*
	- `other`: A running "temp directory". *Not needed, and may contain video or other large files.*
	- `past-gigs`: A large archive folder containing all past gig folders. These are moved from `future-gigs` to `past-gigs` after a gig. The data needed for database tables that might exist here also exists elsewhere with great likelihood, so *probably not needed.*
	- `sheets`: Contains transcriptions, sheet music, arrangements, and other performance info, mainly as PDF files. *Large context load because of PDF format; not needed yet.*
	- `tarjoukset.txt`: Current ongoing inquiries that need our company personnel's attention, in no particular format. *I will take care of this, not needed yet.*
- `info`: A lot of miscellaneous info here. A lot of data here concerns our repertoire and some live tech config stuff. The `gigs-yyyy.xlsx` files essentially contain the status of every gig inquiry, be it pending, confirmed, or already delivered. **This data will be central in this transformation.**
	- `archive`: Self-explanatory; *can be ignored for now.*
	- `stats`: Ad-hoc aggregate statistics reports. *Probably not ever needed anymore.*
- `gig-invoicing.xlsx`: Contains a record of every gig invoice we have sent and the paycheck history for all external musicians that have invoiced our company. This is history data, but might act as a good reference.
- `sales`: Contains mail templates for different languages, platforms, and customer types, in a hierarchical directory structure. Templates in other languages are not yet implemented. Also, contains `price-calculator.xlsx`, that has been used to calculate offers. **These files are essential to the transformation.**
- `internal-bookkeeping.xlsx`: Contains the hourly bookkeeping for our partners that acts as basis for salary calculation. Also contains travel invoice calculation logic.
- `musiikin-soitto-template.xlsx`: An invoice template for gig invoicing.
- `yymmdd-customer`: This acts as a template folder for a new booked gig. The price calculator here is the same as in `sales/`; it is forked here to keep track of quote calculation logic. The `.odt` files are constructed from the setlist, and are printed for human use on actual gigs.

# Flows

## Flow trigger: customer sends a business inquiry to mail

Until now, when receiving a new quote from a customer, the basic process flow has been as follows:

- Log work start time and work performer into `internal-bookkeeping.xlsx`'s *tuntikrjp YY* tab; log work start time and quote-identifying information (inquiry date and customer name) to `internal-bookkeeping.xlsx`'s *myynnit-tuntikrjp* tab (for salary bookkeeping purposes)
- Copy the template `future-gigs/quotes/gig-info-yymmdd-firstname-lastname.txt` file into `future-gigs/quotes`, name file accordingly, and fill hard (customer name, contact name, etc.) and soft (special wishes, etc.) data semi-structuredly here, distilled from the inquiry email (**requires human/LLM**)
- Save phone number into iOS contacts
- If the date is already booked/otherwise not viable, send `sales/{lang}/{channel}/{customer-type}/sorry-were-booked.txt` mail to customer. Otherwise, proceed with the quote flow. **Requires human supervision**, and can pend for a long time, since some dates might need deliberation, even if they're still free at the time of inquiry.
- Calculate distance to the venue in two ways: first, as a pure distance from Turku, on which a distance premium is calculated, and second, as total driving distance concerning the gig. Google Maps has been used in this step. Route calculation includes a lot of heuristics, but it doesn't need to be exact, and therefore this step can be performed by an LLM, generally speaking.
- Calculate the offer based on the distance and other specs (see `sales/price-calculator.xlsx`). Record the appropriate data fields into the `future-gigs/quotes/gig-info-yymmdd-firstname-lastname.txt` file generated above (because no inquiry-specific sales calculator file exists yet). Example contents can be found in existing quote txt files in `future-gigs/quotes`.
- Copy tha appropriate mail template file from `sales` to the Protonmail composer, adjust the mail accordingly, and send it. The mail can and should be composed by an LLM, but need human review step. This composition should be implemented so that the mail body is only adjusted by LLM's at very specific points, and otherwise straight-up copied from the template mail, to be stylistically in line with our brand guidelines.
- Log work end time into `internal-bookkeeping.xlsx`'s *tuntikrjp YY* and *myynnit-tuntikrjp* tab.

## Flow trigger: customer accepts and offer, and a gig is confirmed

To be recorded here as we progress.
