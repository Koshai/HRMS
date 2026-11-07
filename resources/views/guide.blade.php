<x-layouts::app :title="__('portal.guide')">
    <article class="mx-auto max-w-3xl leading-7 text-zinc-700 dark:text-zinc-300 [&_a]:underline [&_h1]:text-3xl [&_h1]:font-semibold [&_h1]:text-zinc-950 dark:[&_h1]:text-white [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-zinc-950 dark:[&_h2]:text-white [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:ps-6 [&_p]:my-3">
        {!! $guide !!}
    </article>
</x-layouts::app>
