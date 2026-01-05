import { Card } from "@/components/ui/card";

interface FeatureCardProps {
  title: string;
  description: string;
  image: string;
}

const FeatureCard = ({ title, description, image }: FeatureCardProps) => {
  return (
    <Card className="p-6 shadow-card hover:shadow-elegant transition-smooth hover:scale-105 border-border overflow-hidden group">
      <div className="mb-4 rounded-xl overflow-hidden h-48 relative">
        <img
          src={image}
          alt={title}
          className="w-full h-full object-cover transition-smooth group-hover:scale-110"
        />
        <div className="absolute inset-0 bg-gradient-to-t from-card/80 to-transparent" />
      </div>
      <h3 className="text-xl font-bold text-foreground mb-3">{title}</h3>
      <p className="text-muted-foreground leading-relaxed">{description}</p>
    </Card>
  );
};

export default FeatureCard;
