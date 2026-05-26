from __future__ import annotations


def language_consistency(user_dominant: str, reply_dominant: str) -> float:
    if user_dominant == reply_dominant:
        return 1.0
    if user_dominant in {"mixed", "translit_mixed"} and reply_dominant in {"mixed", "kk", "ru", "translit_mixed"}:
        return 1.0

    return 0.0


def formality_delta(user_formality: str, reply_formality: str) -> float:
    order = {"formal": 2, "neutral": 1, "casual": 0}
    return abs(order.get(user_formality, 1) - order.get(reply_formality, 1))
