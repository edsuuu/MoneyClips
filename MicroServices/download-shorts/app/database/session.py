from collections.abc import Iterator

from sqlalchemy import create_engine, text
from sqlalchemy.engine import make_url
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.config.settings import settings


class Base(DeclarativeBase):
    pass


engine = create_engine(settings.database_url, pool_pre_ping=True, future=True)
SessionLocal = sessionmaker(engine, autoflush=False, autocommit=False, expire_on_commit=False)


def ensure_database() -> None:
    """Cria o database (CREATE DATABASE IF NOT EXISTS) se ainda não existir.

    O alembic/SQLAlchemy só criam tabelas dentro de um schema já existente,
    então isso roda antes do `upgrade head` para o primeiro boot funcionar
    sem precisar criar o banco na mão.
    """
    url = make_url(settings.database_url)
    database = url.database
    server_engine = create_engine(url.set(database=None), isolation_level="AUTOCOMMIT", future=True)
    try:
        with server_engine.connect() as conn:
            conn.execute(
                text(
                    f"CREATE DATABASE IF NOT EXISTS `{database}` "
                    "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                )
            )
    finally:
        server_engine.dispose()


def get_session() -> Iterator[Session]:
    with SessionLocal() as session:
        yield session


def init_db() -> None:
    import app.database.models  # noqa: F401

    Base.metadata.create_all(bind=engine)
