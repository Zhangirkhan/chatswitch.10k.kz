from __future__ import annotations

import typer

from kz_locale_ai.eval.runner import run_detect_eval
from kz_locale_ai.pipeline.dedupe import dedupe_file
from kz_locale_ai.pipeline.export_openai import export_openai
from kz_locale_ai.pipeline.ingest import ingest_file
from kz_locale_ai.pipeline.split import split_file
from kz_locale_ai.pipeline.validate import validate_file

app = typer.Typer(help="Kazakhstan locale AI toolkit")


@app.command("ingest")
def ingest(
    input: str = typer.Option(..., "--input"),
    format: str = typer.Option("openai_chat", "--format"),
    out: str = typer.Option(..., "--out"),
) -> None:
    count = ingest_file(input, format, out)
    typer.echo(f"Ingested {count} rows → {out}")


@app.command("dedupe")
def dedupe(
    input: str = typer.Option(..., "--in"),
    out: str = typer.Option(..., "--out"),
    threshold: float = typer.Option(0.92, "--threshold"),
) -> None:
    stats = dedupe_file(input, out, threshold)
    typer.echo(f"Deduped: kept={stats['kept']} removed={stats['removed']} → {out}")


@app.command("split")
def split(
    input: str = typer.Option(..., "--in"),
    train: str = typer.Option(..., "--train"),
    val: str = typer.Option(..., "--val"),
    ratio: float = typer.Option(0.9, "--ratio"),
    seed: int = typer.Option(42, "--seed"),
) -> None:
    stats = split_file(input, train, val, ratio, seed)
    typer.echo(f"Split: train={stats['train']} val={stats['val']}")


@app.command("validate")
def validate(
    input: str = typer.Option(..., "--in"),
) -> None:
    stats = validate_file(input)
    typer.echo(f"Valid={stats['valid']} invalid={stats['invalid']}")
    if stats["invalid"] > 0:
        raise typer.Exit(code=1)


@app.command("export-openai")
def export_openai_cmd(
    input: str = typer.Option(..., "--in"),
    out: str = typer.Option(..., "--out"),
    system_prompt_path: str | None = typer.Option(None, "--system-prompt"),
) -> None:
    stats = export_openai(input, out, system_prompt_path)
    typer.echo(f"Exported={stats['exported']} skipped={stats['skipped']} → {out}")


eval_app = typer.Typer(help="Evaluation commands")
app.add_typer(eval_app, name="eval")


@eval_app.command("detect")
def eval_detect(
    benchmark: str = typer.Option(..., "--benchmark"),
    lexicon_root: str | None = typer.Option(None, "--lexicon-root"),
) -> None:
    result = run_detect_eval(benchmark, lexicon_root)
    typer.echo(
        f"Cases={result['total']} dominant_acc={result['dominant_accuracy']:.1f}% "
        f"formality_acc={result['formality_accuracy']:.1f}%"
    )
    if result["dominant_accuracy"] < 70:
        raise typer.Exit(code=1)


if __name__ == "__main__":
    app()
