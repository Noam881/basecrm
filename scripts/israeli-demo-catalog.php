<?php

/**
 * Entirely invented Israeli-styled demonstration data, never sourced from customer rows.
 * Emails use reserved .test domains. Phone numbers are synthetic examples, not a
 * claim about reserved Israeli allocations; this catalog must never send messages.
 * Loading this file performs no application bootstrap, database access or I/O.
 */
final class IsraeliDemoCatalog
{
    public static function rows(): array
    {
        $people = [
            ['key' => 'avi-shalev', 'name' => 'Avi Shalev', 'company' => 'Kedem Workflow Studio', 'city' => 'Tel Aviv', 'address' => '17 HaGefen Street, Suite 3', 'area' => '3', 'sector' => 'Business administration', 'product' => 'Office workflow assessment', 'stage' => 'new', 'source' => 'referral', 'estimate' => '2800.00', 'needs' => 'Create one clear system for client intake, appointments and open tasks.', 'notes' => ['The team currently tracks enquiries in separate notebooks.', 'Avi would like a concise action plan before choosing ongoing support.']],
            ['key' => 'tamar-ben-ami', 'name' => 'Tamar Ben Ami', 'company' => 'Galil Desk Systems', 'city' => 'Haifa', 'address' => '8 HaShita Street, Office 12', 'area' => '4', 'sector' => 'Technical support services', 'product' => 'Monthly office management', 'stage' => 'contacted', 'source' => 'google_search', 'estimate' => '4200.00', 'needs' => 'Reduce missed customer requests and organize the weekly service schedule.', 'notes' => ['Completed an introductory call about the service desk workload.', 'Tamar will share an example weekly schedule before the next discussion.']],
            ['key' => 'eitan-katz', 'name' => 'Eitan Katz', 'company' => 'Nitzan Workshop House', 'city' => 'Jerusalem', 'address' => '23 HaTamar Street, Unit 5', 'area' => '2', 'sector' => 'Furniture workshops', 'product' => 'Cash-flow planning package', 'stage' => 'qualified', 'source' => 'website', 'estimate' => '5600.00', 'needs' => 'Plan material purchases around customer deposits and workshop deadlines.', 'notes' => ['Reviewed the gap between ordering materials and collecting final balances.', 'The proposed scope includes a rolling cash-flow view and a weekly review routine.']],
            ['key' => 'shira-rosen', 'name' => 'Shira Rosen', 'company' => 'Carmel Flow Studio', 'city' => 'Netanya', 'address' => '11 HaRakefet Street, Suite 7', 'area' => '9', 'sector' => 'Interior design', 'product' => 'Financial operations setup', 'stage' => 'won', 'source' => 'instagram', 'estimate' => '6800.00', 'needs' => 'Separate project budgets, supplier commitments and collection milestones.', 'notes' => ['Shira approved the scope and the agreed project timetable.', 'The first working session will cover project categories and the monthly reporting rhythm.']],
            ['key' => 'uri-ben-david', 'name' => 'Uri Ben David', 'company' => 'Ofek Homewares Workshop', 'city' => 'Petah Tikva', 'address' => '6 HaAlon Street, Office 4', 'area' => '3', 'sector' => 'Homewares retail', 'product' => 'Office process review', 'stage' => 'lost', 'source' => 'facebook', 'estimate' => '3200.00', 'needs' => 'Coordinate stock queries and supplier follow-ups without duplicate work.', 'notes' => ['Uri decided to postpone external support until after the stock review.', 'The team will revisit the project after its internal responsibilities have been assigned.']],
            ['key' => 'liat-friedman', 'name' => 'Liat Friedman', 'company' => 'Aviv Process Lab', 'city' => 'Raanana', 'address' => '14 HaIlan Street, Suite 2', 'area' => '9', 'sector' => 'Professional training', 'product' => 'Administration discovery session', 'stage' => 'new', 'source' => 'linkedin', 'estimate' => '1800.00', 'needs' => 'Connect course registrations, participant questions and the teaching calendar.', 'notes' => ['The enquiry describes a growing programme of small-group workshops.', 'Liat is gathering the current registration steps for an introductory discussion.']],
            ['key' => 'omer-azulay', 'name' => 'Omer Azulay', 'company' => 'Negev Paperworks Studio', 'city' => 'Beersheba', 'address' => '9 HaDaf Street, Unit 6', 'area' => '8', 'sector' => 'Print and packaging design', 'product' => 'Supplier coordination package', 'stage' => 'contacted', 'source' => 'event', 'estimate' => '3900.00', 'needs' => 'Keep supplier quotations, artwork approvals and delivery commitments together.', 'notes' => ['Omer described several jobs moving between design and production at the same time.', 'A follow-up call will confirm which approval steps cause the longest delays.']],
            ['key' => 'michal-dahan', 'name' => 'Michal Dahan', 'company' => 'Shahar Garden Office', 'city' => 'Rishon LeZion', 'address' => '21 HaNarkis Street, Office 8', 'area' => '3', 'sector' => 'Landscape planning', 'product' => 'Monthly financial administration', 'stage' => 'qualified', 'source' => 'referral', 'estimate' => '4800.00', 'needs' => 'Create a repeatable routine for supplier paperwork and project expense tracking.', 'notes' => ['Michal confirmed that a regular monthly service is preferable to an isolated clean-up.', 'The proposal will separate the initial organization phase from ongoing support.']],
            ['key' => 'itai-peretz', 'name' => 'Itai Peretz', 'company' => 'Rakefet Craft Studio', 'city' => 'Herzliya', 'address' => '5 HaRimon Street, Suite 9', 'area' => '9', 'sector' => 'Handmade gifts', 'product' => 'Seasonal cash-flow review', 'stage' => 'won', 'source' => 'returning_customer', 'estimate' => '3500.00', 'needs' => 'Prepare purchasing and collection plans ahead of the busiest sales period.', 'notes' => ['The agreed review includes supplier payment dates and expected customer receipts.', 'Itai has approved the first planning session and will prepare the order list.']],
            ['key' => 'hila-ashkenazi', 'name' => 'Hila Ashkenazi', 'company' => 'Keshet Learning Studio', 'city' => 'Rehovot', 'address' => '18 HaSigalit Street, Unit 3', 'area' => '8', 'sector' => 'Educational workshops', 'product' => 'Booking workflow setup', 'stage' => 'lost', 'source' => 'google_ads', 'estimate' => '4600.00', 'needs' => 'Simplify workshop bookings, attendance lists and payment tracking.', 'notes' => ['Hila chose to keep the current booking arrangement for the coming term.', 'The discussion ended with a clear summary of the options for a later review.']],
            ['key' => 'yonatan-golan', 'name' => 'Yonatan Golan', 'company' => 'Gefen Wellness Office', 'city' => 'Kfar Saba', 'address' => '12 HaZayit Street, Office 11', 'area' => '9', 'sector' => 'Wellness practice administration', 'product' => 'Client administration assessment', 'stage' => 'new', 'source' => 'website', 'estimate' => '2400.00', 'needs' => 'Organize appointment enquiries and routine office tasks in one shared view.', 'notes' => ['Yonatan is comparing ways to make reception cover more consistent.', 'The initial conversation will focus on handovers and repeat administrative tasks.']],
            ['key' => 'neta-biton', 'name' => 'Neta Biton', 'company' => 'Hadar Workshop Collective', 'city' => 'Modiin', 'address' => '7 HaEshel Street, Suite 4', 'area' => '8', 'sector' => 'Creative workshop venue', 'product' => 'Office coordination retainer', 'stage' => 'contacted', 'source' => 'email_campaign', 'estimate' => '5200.00', 'needs' => 'Coordinate instructors, venue bookings and participant updates each week.', 'notes' => ['Neta outlined the current division of work between venue staff and instructors.', 'The next discussion will agree which recurring responsibilities can be centralized.']],
            ['key' => 'gilad-hadad', 'name' => 'Gilad Hadad', 'company' => 'Lahav Design Bureau', 'city' => 'Ashdod', 'address' => '16 HaHadas Street, Unit 2', 'area' => '8', 'sector' => 'Product design services', 'product' => 'Project administration setup', 'stage' => 'qualified', 'source' => 'linkedin', 'estimate' => '6100.00', 'needs' => 'Track project stages, purchasing approvals and client decisions consistently.', 'notes' => ['The team agreed that approval ownership must be visible for every active project.', 'Gilad is reviewing the proposed setup together with the studio manager.']],
            ['key' => 'adi-weiss', 'name' => 'Adi Weiss', 'company' => 'Zohar Food Studio', 'city' => 'Hadera', 'address' => '4 HaLotem Street, Office 6', 'area' => '4', 'sector' => 'Food styling services', 'product' => 'Expense and supplier workflow', 'stage' => 'won', 'source' => 'referral', 'estimate' => '4400.00', 'needs' => 'Collect project receipts and supplier documentation before the monthly review.', 'notes' => ['Adi approved a shared collection routine for receipts and supplier documents.', 'The onboarding meeting will define the project folders and review responsibilities.']],
            ['key' => 'lior-nachmani', 'name' => 'Lior Nachmani', 'company' => 'Eshkol Family Services', 'city' => 'Holon', 'address' => '19 HaBustan Street, Suite 5', 'area' => '3', 'sector' => 'Home support coordination', 'product' => 'Scheduling process review', 'stage' => 'lost', 'source' => 'outreach', 'estimate' => '2900.00', 'needs' => 'Reduce scheduling changes and make daily responsibilities easier to follow.', 'notes' => ['Lior selected an internal coordinator for the next operating period.', 'No further meeting is planned while the internal arrangement is being evaluated.']],
            ['key' => 'maya-yarden', 'name' => 'Maya Yarden', 'company' => 'Maayan Workspace Studio', 'city' => 'Ramat Gan', 'address' => '10 HaNahal Street, Office 10', 'area' => '3', 'sector' => 'Shared workspace services', 'product' => 'Member administration assessment', 'stage' => 'new', 'source' => 'instagram', 'estimate' => '2600.00', 'needs' => 'Bring member requests, room bookings and recurring office work into one routine.', 'notes' => ['Maya submitted an overview of the current member support process.', 'The first conversation will identify the small changes with the quickest practical benefit.']],
            ['key' => 'yair-elbaz', 'name' => 'Yair Elbaz', 'company' => 'Naveh Digital Workshop', 'city' => 'Nahariya', 'address' => '15 HaDekel Street, Unit 7', 'area' => '4', 'sector' => 'Digital content production', 'product' => 'AI-assisted office workflow review', 'stage' => 'contacted', 'source' => 'youtube', 'estimate' => '4700.00', 'needs' => 'Reduce repetitive administrative writing while keeping client information organized.', 'notes' => ['Yair wants practical examples before introducing additional tools to the team.', 'A second call will compare the current handover process with the proposed workflow.']],
            ['key' => 'rotem-saada', 'name' => 'Rotem Saada', 'company' => 'Oren Planning House', 'city' => 'Kiryat Ono', 'address' => '3 HaOren Street, Suite 8', 'area' => '3', 'sector' => 'Event planning', 'product' => 'Event cash-flow planning', 'stage' => 'qualified', 'source' => 'facebook', 'estimate' => '5900.00', 'needs' => 'Align client deposits with supplier commitments across several planned events.', 'notes' => ['Rotem confirmed the need for an event-by-event view of expected cash movement.', 'The proposed work includes a simple weekly review and clear decision points.']],
            ['key' => 'dana-abulafia', 'name' => 'Dana Abulafia', 'company' => 'Barak Artisan Office', 'city' => 'Ramat HaSharon', 'address' => '13 HaShaked Street, Office 3', 'area' => '3', 'sector' => 'Artisan business support', 'product' => 'Office operations launch package', 'stage' => 'won', 'source' => 'google_search', 'estimate' => '7200.00', 'needs' => 'Set up a sustainable office routine before adding another member of staff.', 'notes' => ['Dana approved the launch package and the division of responsibilities.', 'The initial working session will establish the weekly task and document routines.']],
            ['key' => 'amit-naveh', 'name' => 'Amit Naveh', 'company' => 'Tamar Olive Design', 'city' => 'Afula', 'address' => '20 HaEla Street, Unit 4', 'area' => '4', 'sector' => 'Small business branding', 'product' => 'Client process improvement review', 'stage' => 'lost', 'source' => 'event', 'estimate' => '3800.00', 'needs' => 'Make enquiry follow-ups and project handovers more consistent.', 'notes' => ['Amit decided to revisit the review once the current project cycle is complete.', 'The closing conversation recorded the intended improvements without scheduling further work.']],
        ];
        $methods = ['email', 'phone', 'whatsapp', 'email', 'any'];
        $times = [['mornings'], ['afternoons'], ['evenings'], ['mornings', 'afternoons'], ['anytime']];
        $days = [['sunday', 'tuesday', 'thursday'], ['monday', 'wednesday'], ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'], ['friday', 'saturday'], ['tuesday', 'thursday']];
        $sourceLabels = ['referral' => 'Referral', 'google_search' => 'Google search', 'website' => 'Website', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'event' => 'Event / trade show', 'returning_customer' => 'Returning customer', 'google_ads' => 'Google Ads', 'email_campaign' => 'Email campaign', 'outreach' => 'Direct outreach', 'youtube' => 'YouTube'];
        $ages = ['new' => 2, 'contacted' => 12, 'qualified' => 24, 'won' => 55, 'lost' => 42];
        $rows = [];
        foreach ($people as $index => $person) {
            $sequence = $index + 1;
            $slug = str_replace('-', '.', $person['key']);
            $companySlug = strtolower(str_replace(' ', '.', $person['company']));
            $officePhone = '+972 '.$person['area'].'-000-01'.sprintf('%02d', $sequence);
            $closed = in_array($person['stage'], ['won', 'lost'], true);
            $rows[] = [
                'key' => $person['key'],
                'age_days' => $ages[$person['stage']] + intdiv($index, 5),
                'contact' => [
                    'name' => $person['name'],
                    'email' => $slug.'@demo.example.test',
                    'phone' => '+972 50-000-01'.sprintf('%02d', $sequence),
                    'secondary_phone' => $officePhone,
                    'secondary_phone_label' => 'office',
                    'preferred_contact_method' => $methods[$index % 5],
                    'preferred_contact_times' => $times[$index % 5],
                    'preferred_contact_days' => $days[$index % 5],
                    'address' => $person['address'],
                    'city' => $person['city'],
                    'country' => 'Israel',
                    'source' => $person['source'],
                ],
                'company' => [
                    'name' => $person['company'],
                    'email' => $companySlug.'@demo.example.test',
                    'phone' => $officePhone,
                    'registration_number' => 'DEMO-51'.sprintf('%06d', $sequence),
                ],
                'lead' => [
                    'title' => $person['product'],
                    'status_id' => $person['stage'],
                    'priority' => ['normal', 'high', 'normal', 'high', 'low'][$index % 5],
                    'source' => $sourceLabels[$person['source']],
                    'business_sector' => $person['sector'],
                    'product_sold' => $person['product'],
                    'needs' => $person['needs'],
                    'estimated_value' => $person['estimate'],
                    'final_value' => $person['stage'] === 'won' ? $person['estimate'] : null,
                    'loss_reason' => $person['stage'] === 'lost' ? 'The business chose to defer the project or use internal capacity.' : null,
                    'privacy_policy_accepted' => $index % 7 === 0 ? null : ($index % 7 !== 1),
                ],
                'contact_note' => [
                    'Please email a short agenda before the next conversation.',
                    'A short scheduled call is easier to manage than an unscheduled call.',
                    'Prefers a brief written summary after each discussion.',
                    'Available on the selected days; confirm a time before scheduling.',
                    'Either a call or an email is suitable for arranging the next conversation.',
                ][$index % 5],
                'notes' => $person['notes'],
                'follow_up' => $closed ? null : [
                    'title' => match ($person['stage']) {
                        'new' => 'Arrange an introductory call with '.$person['name'],
                        'contacted' => 'Confirm the next discussion with '.$person['name'],
                        default => 'Review the proposed scope with '.$person['name'],
                    },
                    'days_offset' => 2 + ($index % 4),
                    'hour' => 9 + ($index % 6),
                ],
            ];
        }

        return $rows;
    }
}
