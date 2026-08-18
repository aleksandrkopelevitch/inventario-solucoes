# Corporate CATI deck template

`cati-template.pptx` is the Leo Madeiras presentation template the Architecture
Committee's decks are built on: the slide master, the theme (Century Gothic on
`043420` / `119E07` / `DAFF06`, 16:9) and all six layouts —

| # | Layout | Used for |
|---|---|---|
| 1 | `Fundo branco com título` | every content slide |
| 2 | `Fundo escuro limpo com objetos` | the closing slide |
| 3 | `Fundo escuto sólido` | the cover |
| 4 | `Title Only` | — |
| 5 | `Full title, right Image` | — |
| 6 | `DEFAULT` | — |

**It has no slides.** That is the point: the renderer adds slides that reference
these layouts by placeholder, so every deck inherits the corporate font, size,
colour and geometry without the renderer computing any of it. Fidelity lives in
this file, not in the code that writes slides.

## Where it came from

Built by `scripts/build_template.py` from a real approved deck
(`CATI_SKBridge_SkyMob_11-08-26_1.pptx`):

```
.venv-cati/bin/python scripts/build_template.py \
    --source CATI_SKBridge_SkyMob_11-08-26_1.pptx \
    --out resources/cati/cati-template.pptx
```

Using a real deck rather than rebuilding a lookalike is deliberate: a deck that
comes out only *almost* corporate is the failure mode most likely to make
someone redo it by hand, which would end the whole point of the module.

### Why the script also MOVES shapes onto layouts

The first version of this file only dropped the slides, and generated covers
came out as a bare green rectangle with a title on it. The reason: the cover's
identity — the corner brackets, the Leo CONECTADOS logo, the tagline band — was
drawn on **slide 1 itself**, and the `Fundo escuto sólido` layout was
completely empty. Same story for the closing slide.

So the script lifts every non-text shape off the source cover and closing onto
their layouts, rewriting the relationship ids as it goes (an `r:embed` only
means anything relative to the part that declares it, so copying the XML alone
gives you a picture pointing at nothing). That is what lets `render_deck.py`
stay dumb: it places text, tables and pictures, and every bit of brand identity
comes from here.

## Replacing it

Re-run the script against the new deck. Two things must stay stable:

- **Layout NAMES** — the renderer resolves layouts by name, so a rename is a
  silent breakage.
- **Which slide is the cover and which is the closing** — `--cover-slide` and
  `--closing-slide` default to the first and the last.

Nothing else in the app reads this file.
