Mossfield Organic Farm Web App

I am developing a web app using Laravel + MySQL for Mossfield Organic Farm, a dairy producer that bottles their own milk, produces various types of cheese, and makes yoghurt. The goal is to move away from spreadsheets and introduce full batch traceability, order management, invoicing, and future planning features.
🧀 Product Types & Structure

Mossfield produces and sells:

    Milk: 1L and 2L bottles

    Yoghurt: 250g and 500g tubs

    Cheese: Farmhouse, Garlic & Basil, Tomato & Herb, Cumin Seed, Mature (sold as wheels and vacuum packs)

Notes:

    For milk and yoghurt, we need to:

        Track total litres/kgs per batch

        Log the number of bottles/tubs produced per packaging size

    For cheese:

        Production creates whole wheels

        These are later cut into vacuum packs for sale

        Each vacuum pack must be traceable back to its original wheel

        Stock levels must show:

            Whole wheels in stock

            Remaining quantity available after vacuum packing

            Vacuum-packed units in stock

📦 Batch Tracking & Traceability

Each product has its own batch coding format:
Product	Batch Code Format	Notes
Milk	Mddmmyy	Bottling date is sufficient
Yoghurt	Yddmmyy	Production date
Cheese	Gddmmyy	Production date + maturation period
Additional Requirements:

    System must record quantity produced per batch:

        Milk and yoghurt in litres/kgs and packaging units

        Cheese by number of wheels (estimated weight until sold)

    Cheese maturation must be tracked:

        Each cheese type has a configurable maturation period

        System should automatically flag batches as "Ready to Sell"

🧾 Orders

    Orders contain:

        Customer name

        Products and quantities

        Order date

        Delivery run/group (e.g., “Dublin run”, “Midland Fine Foods” group)

    Regular orders exist but most change weekly

    Should support both regular and ad-hoc customers

    It should be easy to duplicate and adjust previous orders

🚚 Delivery Dockets

    Delivery dockets do not need to be auto-generated

    They should include:

        Product name

        Quantity

        Optionally, batch codes and barcodes

💸 Invoicing

    Invoices are generated per order, immediately

    Line items must include:

        Product name

        Quantity

        Unit price

        Total per line

    Prices are usually consistent but:

        System should support per-customer pricing if needed

    Customers do not need VAT numbers, but standard billing details should be stored

📅 Cheese Production Planning

    Each cheese type matures at different rates

    System should:

        Allow defining a custom maturation time per cheese

        Automatically mark when a batch is ready to sell

        Provide a calendar/timeline view to visualize stock maturity and availability

🌐 Future Features (Customer Portal)

While not needed immediately, please keep this in mind when designing the database and architecture:

    Customers should have individual login access

    They can:

        View their current and past orders

        Download invoices

        See product prices

❗ Priorities

    Batch traceability is the most critical for compliance and food safety

    Cheese production tracking and planning is next highest

    Order and invoice management must be intuitive and flexible

    Stock tracking of wheels and vacuum packs must stay accurate and real-time

