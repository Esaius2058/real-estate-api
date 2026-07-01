from sqlalchemy.ext.asyncio import create_async_engine, async_sessionmaker
from sqlalchemy.orm import declarative_base
from sqlalchemy import Column, Integer, Enum, Numeric, BigInteger, JSON, Date, String, Text, DateTime
from sqlalchemy.sql import func
from app.core.config import settings

# Example URL format: mysql+aiomysql://user:password@localhost:3306/makao
engine = create_async_engine(settings.MYSQL_URL, pool_pre_ping=True)
AsyncSessionLocal = async_sessionmaker(engine, expire_on_commit=False)

Base = declarative_base()

class ChatMessageDB(Base):
    __tablename__ = "chat_messages"

    id = Column(Integer, primary_key=True, index=True)
    session_id = Column(String(255), index=True, nullable=False)
    role = Column(String(50), nullable=False)  # 'user' or 'model'
    agency_id = Column(Integer, nullable=False)
    content = Column(Text, nullable=False)
    created_at = Column(DateTime(timezone=True), server_default=func.now())


class PropertyDB(Base):
    __tablename__ = "properties"

    id = Column(BigInteger, primary_key=True, autoincrement=True)
    agency_id = Column(BigInteger, index=True, nullable=False)
    user_id = Column(BigInteger, index=True, nullable=False)
    title = Column(String(255), nullable=False)
    type = Column(String(255), nullable=False, default='Apartment')
    price = Column(Numeric(15, 2), index=True, nullable=False)
    service_charge = Column(Numeric(10, 2), nullable=True)
    current_rent = Column(Numeric(10, 2), nullable=True)
    location = Column(String(255), index=True, nullable=False)
    city = Column(String(255), nullable=False)
    bedrooms = Column(Integer, nullable=False)
    baths = Column(Integer, nullable=False)
    sqft = Column(Integer, nullable=False)
    description = Column(Text, nullable=False)
    amenities = Column(JSON, nullable=True)
    status = Column(Enum('Active', 'Under Contract', 'Closed', 'Expired', name='property_status'), index=True, nullable=False, default='Active')
    roi_forecast = Column(JSON, nullable=True)
    contract_end_date = Column(Date, nullable=True)
    created_at = Column(DateTime, nullable=True)
    updated_at = Column(DateTime, nullable=True)
    deleted_at = Column(DateTime, nullable=True)